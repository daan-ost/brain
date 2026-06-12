<?php

/**
 * Check Sanctum Installation
 */
echo '<h2>🔍 Sanctum Check</h2>';
echo '<pre>';

chdir('..');
$root = getcwd();

// Check if Sanctum is installed
echo "=== SANCTUM PACKAGE CHECK ===\n";

$sanctumPath = 'vendor/laravel/sanctum';
if (is_dir($sanctumPath)) {
    echo "✅ Laravel Sanctum folder exists\n";

    // Check for Sanctum.php
    $sanctumClass = $sanctumPath.'/src/Sanctum.php';
    if (file_exists($sanctumClass)) {
        echo "✅ Sanctum.php class file exists\n";
    } else {
        echo "❌ Sanctum.php class file MISSING!\n";
    }
} else {
    echo "❌ Laravel Sanctum NOT INSTALLED!\n";
    echo "   Path checked: {$sanctumPath}\n";
}

// Check composer.json
echo "\n=== COMPOSER.JSON CHECK ===\n";
if (file_exists('composer.json')) {
    $composer = json_decode(file_get_contents('composer.json'), true);

    if (isset($composer['require']['laravel/sanctum'])) {
        echo '✅ laravel/sanctum in composer.json: '.$composer['require']['laravel/sanctum']."\n";
    } else {
        echo "❌ laravel/sanctum NOT in composer.json require!\n";
    }
}

// Check composer.lock
echo "\n=== COMPOSER.LOCK CHECK ===\n";
if (file_exists('composer.lock')) {
    $lock = json_decode(file_get_contents('composer.lock'), true);

    $sanctumFound = false;
    foreach ($lock['packages'] as $package) {
        if ($package['name'] === 'laravel/sanctum') {
            $sanctumFound = true;
            echo '✅ laravel/sanctum in composer.lock: '.$package['version']."\n";
            break;
        }
    }

    if (! $sanctumFound) {
        echo "❌ laravel/sanctum NOT in composer.lock!\n";
    }
}

// Check config/sanctum.php
echo "\n=== CONFIG CHECK ===\n";
if (file_exists('config/sanctum.php')) {
    echo "✅ config/sanctum.php exists\n";

    $content = file_get_contents('config/sanctum.php');
    if (strpos($content, "use Laravel\Sanctum\Sanctum;") !== false) {
        echo "⚠️  Config references Sanctum class on line 21\n";
    }
} else {
    echo "✅ config/sanctum.php does NOT exist (not needed if not using)\n";
}

// Check autoload
echo "\n=== AUTOLOAD CHECK ===\n";
if (file_exists('vendor/autoload.php')) {
    require 'vendor/autoload.php';

    if (class_exists('Laravel\Sanctum\Sanctum')) {
        echo "✅ Sanctum class is autoloadable\n";
    } else {
        echo "❌ Sanctum class NOT autoloadable!\n";
    }

    // Check other Laravel classes
    $classes = [
        'Illuminate\Foundation\Application',
        'Illuminate\Support\ServiceProvider',
    ];

    foreach ($classes as $class) {
        if (class_exists($class)) {
            echo "✅ {$class}\n";
        } else {
            echo "❌ {$class} MISSING!\n";
        }
    }
}

echo "\n=== VENDOR FOLDER CHECK ===\n";
$vendorDirs = [
    'vendor/laravel/framework',
    'vendor/laravel/sanctum',
];

foreach ($vendorDirs as $dir) {
    if (is_dir($dir)) {
        echo "✅ {$dir}\n";
    } else {
        echo "❌ {$dir} MISSING!\n";
    }
}

echo '</pre>';

echo '<h3>🎯 DIAGNOSIS:</h3>';

if (! is_dir('vendor/laravel/sanctum')) {
    echo "<p><strong style='color: red;'>PROBLEM: Laravel Sanctum is NOT installed!</strong></p>";
    echo '<p>SOLUTIONS:</p>';
    echo '<ol>';
    echo '<li><strong>Option A:</strong> Install Sanctum<br>';
    echo '   Add to composer.json and run: composer require laravel/sanctum</li>';
    echo '<li><strong>Option B:</strong> Remove Sanctum config (if not using API tokens)<br>';
    echo '   Delete or rename: config/sanctum.php</li>';
    echo '</ol>';
} else {
    echo '<p>Sanctum IS installed. The autoloader might need regeneration.</p>';
}

echo "<p><strong style='color: red;'>DELETE THIS FILE AFTER USE!</strong></p>";
