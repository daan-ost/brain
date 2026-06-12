<?php

/**
 * Emergency Cache Clear
 * Direct file-based cache clearing zonder Laravel te starten
 */
echo '🧹 Emergency Cache Clear<br><br>';

// Change to Laravel root
chdir('..');
$root = getcwd();

echo "📁 Laravel root: {$root}<br><br>";

$deleted = 0;

// Clear bootstrap cache
$bootstrapCache = $root.'/bootstrap/cache';
if (is_dir($bootstrapCache)) {
    $files = glob($bootstrapCache.'/*.php');
    foreach ($files as $file) {
        if (basename($file) !== '.gitignore') {
            if (unlink($file)) {
                echo '✅ Deleted: '.basename($file).'<br>';
                $deleted++;
            }
        }
    }
}

// Clear storage framework cache
$cacheFiles = [
    '/storage/framework/cache/data',
    '/storage/framework/views',
    '/storage/framework/sessions',
];

foreach ($cacheFiles as $dir) {
    $fullPath = $root.$dir;
    if (is_dir($fullPath)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isFile() && $file->getFilename() !== '.gitignore') {
                if (unlink($file->getRealPath())) {
                    $deleted++;
                }
            }
        }
        echo "✅ Cleared: {$dir}<br>";
    }
}

echo "<br>🎉 Deleted {$deleted} cache files<br><br>";

echo 'Now try:<br>';
echo "<a href='/'>Visit Homepage</a><br><br>";

echo "<strong style='color: red;'>VERWIJDER DIT BESTAND NU via Bestandsbeheer!</strong><br>";
echo 'File: public/clear-cache.php';
