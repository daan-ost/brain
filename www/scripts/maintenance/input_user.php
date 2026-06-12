<?php

declare(strict_types=1);

/**
 * Seed baseline data for POC5b (idempotent-ish).
 * - NO migrations/DDL
 * - Writes are tagged with seed_tag = 'poc5b_baseline'
 * - Checks for optional columns (created_at, updated_at, billing_snapshot, etc.)
 *
 * Usage:
 *   /seed_poc5b_baseline.php?seed_key=...&user_id=934&net_800=100.00
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

const SEED_TAG = 'poc5b_baseline';

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// ---------- safety ----------
$seedKey = $_GET['seed_key'] ?? '';
$envKey = env('SEED_KEY', '');

// ---------- inputs ----------
$userId = (int) ($_GET['user_id'] ?? 934);
$net800 = (float) ($_GET['net_800'] ?? 100.00);  // NET price for 800 credits
$vatRate = (float) env('TAX_AMOUNT', 21);
$currency = 'EUR';
$country = 'NL';
$now = Carbon::now();

// ---------- tiny helpers (column-aware inserts/updates) ----------
/** add timestamps if the table has those columns */
$withTimestamps = function (string $table, array $data, Carbon $ts): array {
    if (Schema::hasColumn($table, 'created_at') && ! array_key_exists('created_at', $data)) {
        $data['created_at'] = $ts;
    }
    if (Schema::hasColumn($table, 'updated_at') && ! array_key_exists('updated_at', $data)) {
        $data['updated_at'] = $ts;
    }

    return $data;
};
/** safe insert (auto adds timestamps if present) */
$insert = function (string $table, array $data) use ($withTimestamps, $now) {
    DB::table($table)->insert($withTimestamps($table, $data, $now));
};
/** safe insertGetId (auto timestamps) */
$insertGetId = function (string $table, array $data) use ($withTimestamps, $now) {
    return DB::table($table)->insertGetId($withTimestamps($table, $data, $now));
};
/** safe update (auto adds updated_at if present) */
$safeUpdate = function (string $table, array $where, array $data) use ($now) {
    if (Schema::hasColumn($table, 'updated_at')) {
        $data['updated_at'] = $now;
    }
    DB::table($table)->where($where)->update($data);
};

// ---------- result scaffold ----------
$result = [
    'user_id' => $userId,
    'free_license' => null,
    'onetime_license' => null,
    'created' => [],
    'skipped' => [],
];

try {
    DB::transaction(function () use (
        $userId, $net800, $vatRate, $currency, $country, $now,
        $insert, $insertGetId, $safeUpdate, &$result
    ) {
        // ===== 1) Ensure licenses exist =====
        $freeId = DB::table('licenses')->where('slug', 'free-15')->value('id');
        if (! $freeId) {
            $freeId = $insertGetId('licenses', [
                'slug' => 'free-15',
                'name' => 'Free 15 credits',
                'tier' => 'free',
                'amount' => 0.00,      // NET
                'currency' => $currency,
                'billing_cycle' => 'one_time',
                'credits' => 15,
                'period' => 15,        // days
                'json_restrictions' => null,
                'ordering' => 100,
                'active' => 1,
                'valid_from' => Carbon::today(),
                'valid_until' => null,
            ]);
            $result['created'][] = 'license:free-15';
        } else {
            $result['skipped'][] = 'license:free-15';
        }
        $result['free_license'] = $freeId;

        $one800Id = DB::table('licenses')->where('slug', 'onetime-800-6m')->value('id');
        if (! $one800Id) {
            $one800Id = $insertGetId('licenses', [
                'slug' => 'onetime-800-6m',
                'name' => 'One-time 800 credits (6 months)',
                'tier' => 'onetime',
                'amount' => $net800,   // NET
                'currency' => $currency,
                'billing_cycle' => 'one_time',
                'credits' => 800,
                'period' => 180,
                'json_restrictions' => null,
                'ordering' => 200,
                'active' => 1,
                'valid_from' => Carbon::today(),
                'valid_until' => null,
            ]);
            $result['created'][] = 'license:onetime-800-6m';
        } else {
            // keep price in sync if provided
            if ($net800 > 0) {
                $safeUpdate('licenses', ['id' => $one800Id], ['amount' => $net800]);
            }
            $result['skipped'][] = 'license:onetime-800-6m';
        }
        $result['onetime_license'] = $one800Id;

        // ===== 2) Ensure user exists =====
        $user = DB::table('users')->where('id', $userId)->first();
        if (! $user) {
            $insert('users', [
                'id' => $userId,
                'name' => 'Test User '.$userId,
                'email' => "user{$userId}+seed@example.test",
                'password' => '$2y$12$DmyFakedBcryptHashForLocalOnlyxxxxxj6Xv6nH9', // dummy
                'credits' => 0,
                'credits_updated_at' => $now,
            ]);
            $result['created'][] = 'user:'.$userId;
        } else {
            $result['skipped'][] = 'user:'.$userId;
        }

        // helper: current balance from ledger
        $currentBalance = (int) DB::table('credit_ledger')
            ->where('user_id', $userId)
            ->sum('delta');

        // ===== 3) Signup → Free 15 (if not seeded) =====
        $hasSeededFree = DB::table('user_licenses')
            ->join('licenses', 'licenses.id', '=', 'user_licenses.license_id')
            ->where('user_licenses.user_id', $userId)
            ->where('licenses.slug', 'free-15')
            ->where('user_licenses.source', 'system_signup')
            ->exists();

        if (! $hasSeededFree) {
            $freeExternal = 'free_seed_'.$userId.'_'.$now->format('YmdHis');
            $ulFreeId = $insertGetId('user_licenses', [
                'user_id' => $userId,
                'license_id' => $result['free_license'],
                'status' => 'active',
                'starts_at' => $now,
                'ends_at' => (clone $now)->addDays(15),
                'source' => 'system_signup',
                'external_ref' => $freeExternal,
                'is_current' => 1,
            ]);

            $newBal = $currentBalance + 15;

            // credit_ledger insert (omit timestamps if table has no such cols)
            $ledgerData = [
                'user_id' => $userId,
                'delta' => 15,
                'reason' => 'purchase',
                'meta' => json_encode([
                    'seed_tag' => SEED_TAG,
                    'source' => 'system_signup',
                    'license_assignment_id' => $ulFreeId,
                ], JSON_UNESCAPED_SLASHES),
            ];
            if (Schema::hasColumn('credit_ledger', 'balance_after')) {
                $ledgerData['balance_after'] = $newBal;
            }
            $insert('credit_ledger', $ledgerData);
            $currentBalance = $newBal;

            // users cached credits
            $safeUpdate('users', ['id' => $userId], [
                'credits' => $currentBalance,
                'credits_updated_at' => $now,
            ]);

            // analytics_events (handle missing timestamps)
            $insert('analytics_events', [
                'user_id' => $userId,
                'event' => 'user_signed_up',
                'meta' => json_encode(['seed_tag' => SEED_TAG]),
            ]);
            $insert('analytics_events', [
                'user_id' => $userId,
                'event' => 'license_assigned',
                'meta' => json_encode([
                    'tier' => 'free',
                    'user_license_id' => $ulFreeId,
                    'seed_tag' => SEED_TAG,
                ]),
            ]);

            $result['created'][] = 'user_license:free-15';
        } else {
            $result['skipped'][] = 'user_license:free-15';
        }

        // ===== 4) Purchase → Onetime 800 (if not seeded) =====
        $alreadyHas800 = DB::table('user_licenses')
            ->where('user_id', $userId)
            ->where('license_id', $result['onetime_license'])
            ->where('source', 'mollie')
            ->exists();

        if (! $alreadyHas800) {
            $paymentId = 'tr_seed_onetime_'.$userId.'_'.$now->format('YmdHis');
            $orderId = (string) Str::uuid();

            $tax = round($net800 * $vatRate / 100, 2);
            $gross = round($net800 + $tax, 2);

            // orders (support optional billing_snapshot & timestamps)
            $orderData = [
                'id' => $orderId,
                'payer_type' => 'user',
                'payer_id' => $userId,
                'license_id' => $result['onetime_license'],
                'type' => 'onetime',
                'currency' => $currency,
                'net_amount' => $net800,
                'tax_amount' => $tax,
                'gross_amount' => $gross,
                'country' => $country,
                'vat_id' => null,
                'status' => 'initiated',
                'mollie_payment_id' => $paymentId,
                'mollie_subscription_id' => null,
                'meta' => json_encode([
                    'seed_tag' => SEED_TAG,
                    'note' => 'admin test purchase',
                ]),
            ];
            if (Schema::hasColumn('orders', 'billing_snapshot')) {
                $orderData['billing_snapshot'] = json_encode([
                    'seed_tag' => SEED_TAG,
                    'payer' => 'user',
                    'country' => $country,
                ]);
            }
            $insert('orders', $orderData);

            // user_licenses for onetime
            $ul800Id = $insertGetId('user_licenses', [
                'user_id' => $userId,
                'license_id' => $result['onetime_license'],
                'status' => 'active',
                'starts_at' => $now,
                'ends_at' => (clone $now)->addDays(180),
                'source' => 'mollie',
                'external_ref' => $paymentId,
                'is_current' => 1,
            ]);

            // ledger +800
            $newBal = $currentBalance + 800;
            $ledgerData2 = [
                'user_id' => $userId,
                'delta' => 800,
                'reason' => 'purchase',
                'meta' => json_encode([
                    'seed_tag' => SEED_TAG,
                    'order_id' => $orderId,
                    'payment_id' => $paymentId,
                    'license_assignment_id' => $ul800Id,
                ], JSON_UNESCAPED_SLASHES),
            ];
            if (Schema::hasColumn('credit_ledger', 'balance_after')) {
                $ledgerData2['balance_after'] = $newBal;
            }
            $insert('credit_ledger', $ledgerData2);
            $currentBalance = $newBal;

            // orders → paid
            $safeUpdate('orders', ['id' => $orderId], ['status' => 'paid']);

            // users cached credits
            $safeUpdate('users', ['id' => $userId], [
                'credits' => $currentBalance,
                'credits_updated_at' => $now,
            ]);

            // analytics
            $insert('analytics_events', [
                'user_id' => $userId,
                'event' => 'payment_paid',
                'meta' => json_encode([
                    'order_id' => $orderId,
                    'payment_id' => $paymentId,
                    'type' => 'onetime',
                    'seed_tag' => SEED_TAG,
                ]),
            ]);
            $insert('analytics_events', [
                'user_id' => $userId,
                'event' => 'license_assigned',
                'meta' => json_encode([
                    'tier' => 'onetime',
                    'user_license_id' => $ul800Id,
                    'seed_tag' => SEED_TAG,
                ]),
            ]);

            $result['created'][] = 'user_license:onetime-800-6m';
            $result['created'][] = 'order:paid';
        } else {
            $result['skipped'][] = 'user_license:onetime-800-6m';
        }

        $result['final_balance'] = (int) DB::table('credit_ledger')
            ->where('user_id', $userId)
            ->sum('delta');
    });

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'result' => $result], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'trace' => env('APP_DEBUG') ? $e->getTraceAsString() : null,
        'result' => $result,
    ], JSON_PRETTY_PRINT);
}
