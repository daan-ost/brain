<?php

/**
 * Fix Providers & Autoloader
 * Regenereert autoloader en provider cache
 */
echo '<h2>🔧 Provider & Autoloader Fix</h2>';
echo '<pre>';

chdir('..');
$root = getcwd();
echo "📁 Root: {$root}\n\n";

// Check providers.php
echo "=== PROVIDER CHECK ===\n";
$providersFile = 'bootstrap/providers.php';

if (file_exists($providersFile)) {
    echo "✅ bootstrap/providers.php exists\n";

    $content = file_get_contents($providersFile);
    echo 'File size: '.strlen($content)." bytes\n";

    // Check if it has the right format
    if (strpos($content, 'return [') !== false) {
        echo "✅ Correct format (array return)\n";

        // Count providers
        $providerCount = substr_count($content, '::class');
        echo "Providers found: {$providerCount}\n";
    } else {
        echo "❌ Wrong format!\n";
    }

    echo "\nFirst 500 chars:\n";
    echo substr($content, 0, 500)."...\n";
} else {
    echo "❌ bootstrap/providers.php NOT FOUND!\n";
}

echo "\n=== AUTOLOADER REGENERATION ===\n";

// Delete old cache files
$cacheFiles = [
    'bootstrap/cache/services.php',
    'bootstrap/cache/packages.php',
    'vendor/composer/autoload_classmap.php.tmp',
];

foreach ($cacheFiles as $file) {
    if (file_exists($file)) {
        if (unlink($file)) {
            echo "✅ Deleted: {$file}\n";
        } else {
            echo "⚠️  Could not delete: {$file}\n";
        }
    }
}

// Check if composer is available
echo "\n=== COMPOSER CHECK ===\n";

$composerPaths = [
    'composer',
    'composer.phar',
    '/usr/local/bin/composer',
    '/opt/plesk/php/8.3/bin/composer',
];

$composerCmd = null;
foreach ($composerPaths as $path) {
    $test = @`which {$path} 2>&1`;
    if (! empty($test) && strpos($test, 'not found') === false) {
        $composerCmd = $path;
        break;
    }
    if (file_exists($path)) {
        $composerCmd = "php {$path}";
        break;
    }
}

if ($composerCmd) {
    echo "✅ Composer found: {$composerCmd}\n";
    echo "\n⚠️  Note: shell_exec is disabled, can't auto-run composer\n";
    echo "Please run via Plesk: Composer → Installeren\n";
} else {
    echo "⚠️  Composer not found in common locations\n";
}

echo "\n=== MANUAL STEPS NEEDED ===\n";
echo "1. Go to Plesk → Pakket-afhankelijkheden\n";
echo "2. Click 'Installeren' tab\n";
echo "3. Wait for it to complete\n";
echo "4. Come back and refresh the website\n";

echo "\n=== CONFIG CHECK ===\n";
if (file_exists('config/app.php')) {
    require 'vendor/autoload.php';

    try {
        // Load environment
        if (file_exists('.env')) {
            $lines = file('.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                    [$key, $value] = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    if (! isset($_ENV[$key])) {
                        $_ENV[$key] = $value;
                        putenv("{$key}={$value}");
                    }
                }
            }
        }

        echo "Environment loaded\n";

        // Try to load config
        $config = require 'config/app.php';

        if (isset($config['providers'])) {
            echo "✅ config/app.php has providers array\n";
            echo 'Provider count in config: '.count($config['providers'])."\n";
        } else {
            echo "❌ config/app.php missing 'providers' key\n";
        }

    } catch (Exception $e) {
        echo '❌ Error loading config: '.$e->getMessage()."\n";
    }
}

echo '</pre>';

echo '<h3>🎯 NEXT STEPS:</h3>';
echo '<ol>';
echo '<li>Run Composer Install via Plesk (Pakket-afhankelijkheden → Installeren)</li>';
echo '<li>Refresh this page to verify</li>';
echo '<li>Test the website</li>';
echo "<li><strong style='color: red;'>DELETE THIS FILE!</strong></li>";
echo '</ol>';
