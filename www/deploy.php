<?php

/**
 * Web-based Deployment Script voor Shared Hosting
 *
 * BELANGRIJK:
 * - Gebruik dit ALLEEN als je geen SSH toegang hebt
 * - VERWIJDER dit bestand direct na gebruik!
 * - Beveilig met een secret key
 *
 * Gebruik:
 * 1. Upload dit bestand naar root van je website
 * 2. Bezoek: https://jouw-domein.nl/deploy.php?key=JOUW_SECRET_KEY
 * 3. Verwijder dit bestand direct!
 */

// VERANDER DIT NAAR EEN RANDOM STRING!
define('DEPLOY_KEY', 'staging-deploy-2025-secure-key-xyz');

// Check authorization
if (! isset($_GET['key']) || $_GET['key'] !== DEPLOY_KEY) {
    http_response_code(403);
    exit('Unauthorized');
}

// Prevent timeout
set_time_limit(300);
ini_set('max_execution_time', 300);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Deployment Script</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #1e1e1e; color: #d4d4d4; }
        .success { color: #4ec9b0; }
        .error { color: #f48771; }
        .warning { color: #dcdcaa; }
        .info { color: #569cd6; }
        pre { background: #252526; padding: 15px; border-radius: 5px; overflow-x: auto; }
        h2 { border-bottom: 2px solid #569cd6; padding-bottom: 10px; }
    </style>
</head>
<body>
    <h1>🚀 Deployment Script</h1>
    
<?php

echo '<h2>📋 Pre-flight Checks</h2>';
echo '<pre>';

// Change to Laravel root directory (one level up from public)
if (basename(__DIR__) === 'public') {
    chdir('..');
    echo '📁 Changed to Laravel root: '.getcwd()."\n\n";
}

// Check if we're in the right directory
$expectedFiles = ['artisan', 'composer.json', '.env'];
$allExist = true;

foreach ($expectedFiles as $file) {
    if (file_exists($file)) {
        echo "✅ {$file} found\n";
    } else {
        echo "❌ {$file} NOT found\n";
        $allExist = false;
    }
}

echo '</pre>';

if (! $allExist) {
    echo "<p class='error'>❌ Dit script moet in de root van je Laravel applicatie staan!</p>";
    exit(1);
}

// Check composer
echo '<h2>🎼 Composer</h2>';
echo '<pre>';

$composerPath = trim(shell_exec('which composer 2>/dev/null') ?: shell_exec('which composer.phar 2>/dev/null'));

if (empty($composerPath)) {
    echo "<span class='warning'>⚠️  Composer niet gevonden in PATH</span>\n";
    echo "Probeer lokale composer.phar...\n";

    if (file_exists('composer.phar')) {
        $composerPath = 'php composer.phar';
        echo "<span class='success'>✅ composer.phar gevonden</span>\n";
    } else {
        echo "<span class='error'>❌ Composer niet beschikbaar</span>\n";
        echo "\nManually run:\n";
        echo "  php -r \"copy('https://getcomposer.org/installer', 'composer-setup.php');\"\n";
        echo "  php composer-setup.php\n";
        echo "  php composer.phar install --no-dev --optimize-autoloader\n";
        echo '</pre>';
        $composerPath = null;
    }
} else {
    echo "<span class='success'>✅ Composer found at: {$composerPath}</span>\n";
}

echo '</pre>';

// Run composer install
if ($composerPath) {
    echo '<h2>📦 Installing Dependencies</h2>';
    echo '<pre>';

    $cmd = "{$composerPath} install --no-dev --optimize-autoloader --no-interaction 2>&1";
    echo "Running: {$cmd}\n\n";

    $output = [];
    $returnCode = 0;
    exec($cmd, $output, $returnCode);

    echo implode("\n", $output);

    if ($returnCode === 0) {
        echo "\n<span class='success'>✅ Composer install successful</span>\n";
    } else {
        echo "\n<span class='error'>❌ Composer install failed (exit code: {$returnCode})</span>\n";
    }

    echo '</pre>';
}

// Storage symlink
echo '<h2>🔗 Storage Symlink</h2>';
echo '<pre>';

echo 'Checking storage symlink... ';
$publicStoragePath = __DIR__.'/public/storage';
$storagePath = __DIR__.'/storage/app/public';

if (is_link($publicStoragePath)) {
    echo "<span class='success'>✅ Symlink exists</span>\n";
} elseif (file_exists($publicStoragePath) && ! is_link($publicStoragePath)) {
    echo "<span class='warning'>⚠️  public/storage exists but is not a symlink!</span>\n";
} else {
    echo 'Creating symlink... ';
    $output = [];
    $returnCode = 0;
    exec('php artisan storage:link 2>&1', $output, $returnCode);

    if ($returnCode === 0) {
        echo "<span class='success'>✅</span>\n";
    } else {
        echo "<span class='error'>❌</span>\n";
        echo '  '.implode("\n  ", $output)."\n";
    }
}

echo '</pre>';

// Clear caches
echo '<h2>🧹 Clearing Caches</h2>';
echo '<pre>';

$artisanCommands = [
    'config:cache' => 'Cache configuration',
    'route:cache' => 'Cache routes',
    'view:cache' => 'Cache views',
];

foreach ($artisanCommands as $command => $description) {
    echo "Running: php artisan {$command} ({$description})... ";

    $output = [];
    $returnCode = 0;
    exec("php artisan {$command} 2>&1", $output, $returnCode);

    if ($returnCode === 0) {
        echo "<span class='success'>✅</span>\n";
    } else {
        echo "<span class='warning'>⚠️</span>\n";
        if (! empty($output)) {
            echo '  '.implode("\n  ", $output)."\n";
        }
    }
}

echo '</pre>';

// Check file permissions
echo '<h2>🔐 File Permissions</h2>';
echo '<pre>';

$checkPermissions = [
    'storage' => 0775,
    'bootstrap/cache' => 0775,
];

foreach ($checkPermissions as $path => $expectedPerms) {
    if (is_dir($path)) {
        $currentPerms = fileperms($path) & 0777;
        $currentPermsOctal = decoct($currentPerms);
        $expectedPermsOctal = decoct($expectedPerms);

        if ($currentPerms >= $expectedPerms) {
            echo "✅ {$path}: {$currentPermsOctal}\n";
        } else {
            echo "<span class='warning'>⚠️  {$path}: {$currentPermsOctal} (should be {$expectedPermsOctal})</span>\n";

            // Try to fix
            if (@chmod($path, $expectedPerms)) {
                echo "   → Fixed to {$expectedPermsOctal}\n";
            } else {
                echo "   → Could not auto-fix, please fix manually via FTP\n";
            }
        }
    } else {
        echo "<span class='error'>❌ {$path} doesn't exist</span>\n";
    }
}

echo '</pre>';

// Environment check
echo '<h2>🌍 Environment</h2>';
echo '<pre>';

if (file_exists('.env')) {
    $envContent = file_get_contents('.env');

    // Check APP_ENV en APP_DEBUG, APP_URL is site-specific
    $importantKeys = [
        'APP_ENV' => null,  // Just check it exists
        'APP_DEBUG' => 'false',
    ];

    foreach ($importantKeys as $key => $expectedValue) {
        if (preg_match("/^{$key}=(.+)$/m", $envContent, $match)) {
            $actualValue = trim($match[1]);

            if ($expectedValue === null) {
                // Just check existence
                echo "✅ {$key}={$actualValue}\n";
            } elseif ($actualValue === $expectedValue) {
                echo "✅ {$key}={$actualValue}\n";
            } else {
                echo "<span class='warning'>⚠️  {$key}={$actualValue} (expected: {$expectedValue})</span>\n";
            }
        } else {
            echo "<span class='error'>❌ {$key} not found in .env</span>\n";
        }
    }

    // Also check APP_URL exists
    if (preg_match("/^APP_URL=(.+)$/m", $envContent, $match)) {
        echo "✅ APP_URL=".trim($match[1])."\n";
    } else {
        echo "<span class='error'>❌ APP_URL not found in .env</span>\n";
    }
} else {
    echo "<span class='error'>❌ .env file not found!</span>\n";
}

echo '</pre>';

// Final checks
echo '<h2>✅ Deployment Complete</h2>';
echo '<pre>';
echo 'Deployment finished at: '.date('Y-m-d H:i:s')."\n\n";
echo "<span class='warning'>⚠️  IMPORTANT:</span>\n";
echo '1. Test de website: '.($_SERVER['HTTP_HOST'] ?? 'jouw-domein.nl')."\n";
echo "2. <strong>VERWIJDER DIT BESTAND (deploy.php) DIRECT!</strong>\n";
echo "3. Check error logs: storage/logs/laravel.log\n";
echo '</pre>';

?>

<script>
    // Auto-scroll to bottom
    window.scrollTo(0, document.body.scrollHeight);
</script>

</body>
</html>

