<?php
declare(strict_types=1);

// Config buiten de webroot: /home/ploi/nobrainersbot.com/config.feed.php
// Lokaal: <repo-root>/config.feed.php (3 levels omhoog vanuit public/app/)
$configPath = dirname(__DIR__, 3) . '/config.feed.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'configuration_missing', 'message' => 'Feed config not found']);
    exit;
}
$cfg = require $configPath;

// ── Auth (vóór DB-connect, geen logging nodig bij fout token) ────────────────
$token = $_GET['token'] ?? ($_SERVER['HTTP_X_FEED_TOKEN'] ?? '');
if (!isset($cfg['token']) || !hash_equals($cfg['token'], (string) $token)) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'unauthorized', 'message' => 'Invalid or missing token']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$raw = (string) file_get_contents('php://input');
$remoteIp = isset($_SERVER['HTTP_X_FORWARDED_FOR'])
    ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0])
    : ($_SERVER['REMOTE_ADDR'] ?? null);

// ── Optionele IP-allowlist ───────────────────────────────────────────────────
if (!empty($cfg['allowed_ips']) && $remoteIp !== null) {
    if (!in_array($remoteIp, $cfg['allowed_ips'], true)) {
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized', 'message' => 'IP not in allowlist']);
        exit;
    }
}

// ── DB-verbinding ────────────────────────────────────────────────────────────
try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $cfg['db_host'], $cfg['db_port'], $cfg['db_name']);
    $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
    ]);
} catch (PDOException) {
    http_response_code(500);
    echo json_encode(['error' => 'db_error', 'message' => 'Database unavailable']);
    exit;
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function logRejection(PDO $pdo, string $reason, ?string $body, ?string $ip): void
{
    try {
        $pdo->prepare('INSERT INTO tv_ingest_log (status, reason, raw_body, remote_ip) VALUES (?,?,?,?)')
            ->execute(['rejected', $reason, $body, $ip]);
    } catch (PDOException) {
        // loggen mislukt → stil doorlopen, primaire response niet blokkeren
    }
}

function reject(PDO $pdo, int $code, string $error, string $msg, ?string $body, ?string $ip): never
{
    logRejection($pdo, "{$error}: {$msg}", $body, $ip);
    http_response_code($code);
    echo json_encode(['error' => $error, 'message' => $msg]);
    exit;
}

// ── Parse body ───────────────────────────────────────────────────────────────
$data = json_decode($raw, true);
if (!is_array($data)) {
    reject($pdo, 400, 'invalid_json', 'Body must be a JSON object', $raw, $remoteIp);
}

// ── Validatie ────────────────────────────────────────────────────────────────
const VALID_INDICATORS = ['vzo', 'phobos', 'obv-x-value', 'mfi', 'volumeud'];
const VALID_TIMEFRAMES  = [1, 3, 5, 15, 30, 45, 60, 120, 240];

$signal = (string) ($data['signal'] ?? '');
if (!in_array($signal, VALID_INDICATORS, true)) {
    reject($pdo, 422, 'unknown_indicator', "indicator '{$signal}' is not in the allowed set", $raw, $remoteIp);
}

$svRaw = $data['signalvalue'] ?? null;
if ($svRaw === null || $svRaw === '' || !is_numeric($svRaw)) {
    reject($pdo, 422, 'invalid_signalvalue', 'signalvalue must be numeric', $raw, $remoteIp);
}
$signalvalue = (float) $svRaw;
if ($signal === 'volumeud' && $signalvalue == 0.0) {
    reject($pdo, 422, 'empty_volume_value', 'volumeud signalvalue must not be zero', $raw, $remoteIp);
}

$tfRaw = $data['timeframe'] ?? null;
$timeframe = is_numeric($tfRaw) ? (int) $tfRaw : -1;
if (!in_array($timeframe, VALID_TIMEFRAMES, true)) {
    reject($pdo, 422, 'invalid_timeframe', "timeframe '{$tfRaw}' is not in the allowed set", $raw, $remoteIp);
}

$coin = trim((string) ($data['coin'] ?? ''));
if ($coin === '') {
    reject($pdo, 422, 'missing_coin', 'coin must not be empty', $raw, $remoteIp);
}

$priceRaw = $data['price'] ?? null;
if ($priceRaw === null || $priceRaw === '' || !is_numeric($priceRaw)) {
    reject($pdo, 422, 'missing_price', 'price must be numeric', $raw, $remoteIp);
}
$price    = (float) $priceRaw;
$action   = isset($data['action']) ? (string) $data['action'] : null;

// ── Munt resolven (race-safe upsert → daarna SELECT) ────────────────────────
// Strip 'USDT' van het einde om het korte symbool af te leiden (bv. CLAWUSDT → CLAW)
$symbol = (string) preg_replace('/usdt$/i', '', $coin);

$pdo->prepare(
    'INSERT INTO tv_symbols (symbol, coinpair, timeframe)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE symbol = VALUES(symbol), updated_at = NOW()'
)->execute([$symbol, $coin, $timeframe]);

$stmt = $pdo->prepare('SELECT id FROM tv_symbols WHERE coinpair = ? AND timeframe = ?');
$stmt->execute([$coin, $timeframe]);
$symRow = $stmt->fetch();
$tradingSymbolId = (int) $symRow['id'];

// ── Upsert tick ──────────────────────────────────────────────────────────────
// datetime = huidige UTC-minuut (seconden op 0); received_at = exacte ontvangst (ms)
$datetime = gmdate('Y-m-d H:i:00');

$pdo->prepare(
    'INSERT INTO tv_indicators
       (trading_symbol_id, symbol, coinpair, indicator, datetime, value, price, action, volume_found, remote_ip)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?)
     ON DUPLICATE KEY UPDATE
       value       = VALUES(value),
       price       = VALUES(price),
       action      = VALUES(action),
       received_at = NOW(3),
       id          = LAST_INSERT_ID(id)'
)->execute([$tradingSymbolId, $symbol, $coin, $signal, $datetime, $signalvalue, $price, $action, $remoteIp]);

$rowId = (int) $pdo->lastInsertId();

// ── Succes-response ──────────────────────────────────────────────────────────
echo json_encode(['data' => [
    'id'                => $rowId,
    'trading_symbol_id' => $tradingSymbolId,
    'indicator'         => $signal,
    'datetime'          => $datetime,
]]);
