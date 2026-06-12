<?php

/**
 * Performance optimization for shared hosting
 * Run once, then DELETE
 */
echo '<h1>Performance Optimizer</h1>';
echo '<pre>';

echo "=== Caching Configuration ===\n";
try {
    // Cache config (speeds up subsequent requests)
    exec('cd '.__DIR__.'/.. && php artisan config:cache 2>&1', $output, $return);
    if ($return === 0) {
        echo "✓ Config cached\n";
    } else {
        echo '✗ Config cache failed: '.implode("\n", $output)."\n";
    }
} catch (Exception $e) {
    echo '✗ Cannot cache config: '.$e->getMessage()."\n";
}

echo "\n=== Caching Routes ===\n";
try {
    exec('cd '.__DIR__.'/.. && php artisan route:cache 2>&1', $output, $return);
    if ($return === 0) {
        echo "✓ Routes cached\n";
    } else {
        echo '✗ Route cache failed: '.implode("\n", $output)."\n";
    }
} catch (Exception $e) {
    echo '✗ Cannot cache routes: '.$e->getMessage()."\n";
}

echo "\n=== Optimizing Autoloader ===\n";
try {
    exec('cd '.__DIR__.'/.. && composer dump-autoload --optimize --no-dev 2>&1', $output, $return);
    if ($return === 0) {
        echo "✓ Autoloader optimized\n";
    } else {
        echo "⚠ Composer optimize failed (may need SSH access)\n";
    }
} catch (Exception $e) {
    echo '⚠ Cannot optimize autoloader: '.$e->getMessage()."\n";
}

echo "\n=== Checking PHP Configuration ===\n";
$checks = [
    'opcache.enable' => ini_get('opcache.enable'),
    'opcache.enable_cli' => ini_get('opcache.enable_cli'),
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
];

foreach ($checks as $key => $value) {
    echo "$key: $value\n";
}

echo "\n=== Recommendations ===\n";
echo "1. Change SESSION_DRIVER=file in .env (faster than database)\n";
echo "2. Change CACHE_STORE=file in .env\n";
echo "3. Set APP_DEBUG=false in .env\n";
echo "4. Consider upgrading to VPS if still too slow\n\n";

echo "DELETE THIS FILE!\n";
echo '</pre>';
