<?php

/**
 * Laravel Diagnostic Script
 * Checkt waarom Laravel niet start
 */
echo '<h2>🔍 Laravel Diagnostic</h2>';
echo '<pre>';

// Change to Laravel root
chdir('..');
$root = getcwd();
echo "📁 Root: {$root}\n\n";

// Check critical files
echo "=== FILE CHECK ===\n";
$files = [
    '.env' => 'Environment config',
    'bootstrap/app.php' => 'Bootstrap',
    'vendor/autoload.php' => 'Composer autoload',
    'config/app.php' => 'App config',
];

foreach ($files as $file => $desc) {
    if (file_exists($file)) {
        echo "✅ {$file} ({$desc})\n";
    } else {
        echo "❌ {$file} MISSING!\n";
    }
}

echo "\n=== .ENV CHECK ===\n";
if (file_exists('.env')) {
    $envContent = file_get_contents('.env');
    $lines = explode("\n", $envContent);

    $checks = ['APP_KEY', 'APP_ENV', 'APP_DEBUG', 'DB_CONNECTION', 'DB_DATABASE'];

    foreach ($checks as $key) {
        $found = false;
        $value = '';

        foreach ($lines as $line) {
            if (strpos($line, $key.'=') === 0) {
                $found = true;
                $parts = explode('=', $line, 2);
                $value = isset($parts[1]) ? trim($parts[1]) : '(empty)';

                // Mask sensitive values
                if ($key === 'APP_KEY' && ! empty($value) && $value !== '(empty)') {
                    $value = substr($value, 0, 20).'... (masked)';
                }
                break;
            }
        }

        if ($found) {
            if (empty($value) || $value === '(empty)') {
                echo "⚠️  {$key} = (EMPTY!)\n";
            } else {
                echo "✅ {$key} = {$value}\n";
            }
        } else {
            echo "❌ {$key} = NOT FOUND!\n";
        }
    }
} else {
    echo "❌ .env file NOT FOUND!\n";
}

echo "\n=== AUTOLOADER TEST ===\n";
if (file_exists('vendor/autoload.php')) {
    require 'vendor/autoload.php';
    echo "✅ Autoloader loaded\n";

    // Check if Laravel classes exist
    $classes = [
        'Illuminate\Foundation\Application',
        'Illuminate\Support\Facades\Facade',
        'Illuminate\Support\Facades\Config',
    ];

    foreach ($classes as $class) {
        if (class_exists($class)) {
            echo "✅ {$class}\n";
        } else {
            echo "❌ {$class} NOT FOUND\n";
        }
    }
} else {
    echo "❌ vendor/autoload.php not found!\n";
}

echo "\n=== BOOTSTRAP TEST ===\n";
try {
    if (file_exists('bootstrap/app.php')) {
        echo "Attempting to load bootstrap/app.php...\n";
        $app = require_once 'bootstrap/app.php';
        echo "✅ Bootstrap loaded successfully!\n";
        echo 'App class: '.get_class($app)."\n";
    }
} catch (Exception $e) {
    echo '❌ Bootstrap failed: '.$e->getMessage()."\n";
    echo 'File: '.$e->getFile()."\n";
    echo 'Line: '.$e->getLine()."\n";
}

echo "\n=== PHP INFO ===\n";
echo 'PHP Version: '.PHP_VERSION."\n";
echo 'Memory Limit: '.ini_get('memory_limit')."\n";
echo 'Max Execution Time: '.ini_get('max_execution_time')."\n";

$disabled = ini_get('disable_functions');
if (! empty($disabled)) {
    echo '⚠️  Disabled functions: '.$disabled."\n";
}

echo "\n=== PERMISSIONS ===\n";
$dirs = ['storage', 'bootstrap/cache'];
foreach ($dirs as $dir) {
    if (is_dir($dir)) {
        $perms = substr(sprintf('%o', fileperms($dir)), -4);
        $writable = is_writable($dir) ? 'writable' : 'NOT WRITABLE!';
        echo "{$dir}: {$perms} ({$writable})\n";
    }
}

echo '</pre>';

echo '<h3>🎯 DIAGNOSIS:</h3>';
echo '<p>Check the output above for any ❌ marks. The problem is likely:</p>';
echo '<ul>';
echo '<li>Missing or empty APP_KEY in .env</li>';
echo '<li>Permissions issue on storage/ or bootstrap/cache/</li>';
echo '<li>Autoloader not properly generated</li>';
echo '</ul>';

echo "<p><strong style='color: red;'>VERWIJDER DIT BESTAND NA GEBRUIK!</strong></p>";
