<?php

/**
 * Nuclear Cache Clear
 * Verwijdert ALLE cache, overal
 */
echo '<h2>💣 Nuclear Cache Clear</h2>';
echo '<pre>';

chdir('..');
$root = getcwd();
echo "📁 Root: {$root}\n\n";

$deleted = 0;

// All possible cache locations
$cachePaths = [
    'bootstrap/cache/*.php',
    'storage/framework/cache/data/*',
    'storage/framework/views/*',
    'storage/framework/sessions/*',
    'storage/framework/cache/*.php',
    'storage/logs/*.log',
    'vendor/composer/*.php',
];

echo "=== CLEARING ALL CACHES ===\n";

foreach ($cachePaths as $pattern) {
    $files = glob($pattern);
    if ($files) {
        foreach ($files as $file) {
            if (is_file($file) && basename($file) !== '.gitignore') {
                if (unlink($file)) {
                    echo "✅ Deleted: {$file}\n";
                    $deleted++;
                }
            }
        }
    }
}

// Recursively clear cache/data
function clearDirectory($dir, &$count)
{
    if (! is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        if ($item->isFile() && $item->getFilename() !== '.gitignore') {
            if (unlink($item->getRealPath())) {
                $count++;
            }
        }
    }
}

$dirs = [
    'storage/framework/cache/data',
    'storage/framework/views',
];

foreach ($dirs as $dir) {
    if (is_dir($dir)) {
        $before = $deleted;
        clearDirectory($dir, $deleted);
        $cleared = $deleted - $before;
        echo "✅ Cleared {$dir}: {$cleared} files\n";
    }
}

echo "\n💥 Total deleted: {$deleted} files\n";

// Check where Sanctum might still be referenced
echo "\n=== CHECKING FOR SANCTUM REFERENCES ===\n";

$checkFiles = [
    'config/app.php',
    'bootstrap/app.php',
    'bootstrap/providers.php',
];

foreach ($checkFiles as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        if (stripos($content, 'sanctum') !== false) {
            echo "⚠️  {$file} mentions 'sanctum'\n";

            // Show the lines
            $lines = explode("\n", $content);
            foreach ($lines as $i => $line) {
                if (stripos($line, 'sanctum') !== false) {
                    $lineNum = $i + 1;
                    echo "   Line {$lineNum}: ".trim($line)."\n";
                }
            }
        } else {
            echo "✅ {$file} - no sanctum references\n";
        }
    }
}

// Check config files for sanctum
$configFiles = glob('config/*.php');
foreach ($configFiles as $file) {
    if (basename($file) === 'sanctum.php.disabled') {
        continue;
    }

    $content = file_get_contents($file);
    if (stripos($content, 'sanctum') !== false || stripos($content, 'Sanctum') !== false) {
        echo '⚠️  '.basename($file)." references Sanctum\n";
    }
}

echo '</pre>';

echo '<h3>🎯 NEXT:</h3>';
echo "<p>All caches cleared. Now test: <a href='/'>Homepage</a></p>";
echo "<p><strong style='color: red;'>DELETE THIS FILE!</strong></p>";
