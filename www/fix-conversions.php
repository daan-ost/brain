<?php

/**
 * Script to automatically fix conversion configurations
 * Adds 'show_conversion_options' => true to conversions that have options
 * Adds 'show_conversion_options' => false to conversions without options
 */
$landingPagesPath = __DIR__.'/config/landing_pages.php';

// Read the file
$content = file_get_contents($landingPagesPath);

// List of conversions that need 'show_conversion_options' => true
$needsShowTrue = [
    'images-to-pdf',
    'image-to-pdf',
    'doc-to-pdf',
    'excel-to-pdf',
    'powerpoint-to-pdf',
    'pdf-to-word',
    'pdf-to-excel',
    'pdf-to-powerpoint',
    'pdf-to-text',
    'pdf-to-images',
    'ebook-to-pdf',
    'pdf-to-html',
    'html-to-pdf',
    'pdf-to-txt',
    'pdf-to-csv',
    'csv-to-pdf',
    'pub-to-pdf',
    'rtf-to-pdf',
    'vsd-to-pdf',
    'md-to-pdf',
    'odg-to-pdf',
    'pdf-to-split',
    'pdf-to-rotate',
    'pdf-to-protect',
    'pdf-to-unprotect',
    'pdf-to-delete-pages',
    'compress-pdf',
];

// List of conversions that need 'show_conversion_options' => false
$needsShowFalse = [
    'log-to-pdf',
    'pdfs-to-pdf',
    'epub-to-pdf',
    'repair-pdf',
    'rasterize-pdf',
];

$changes = 0;

foreach ($needsShowTrue as $slug) {
    // Pattern to find the conversion config and add show_conversion_options before 'limits'
    $pattern = "/('{$slug}' => \[[^\]]*?'job' => 'generic_convert_to_pdf',\s*\n)/";

    if (preg_match($pattern, $content)) {
        $replacement = "$1        'show_conversion_options' => true,\n";
        $newContent = preg_replace($pattern, $replacement, $content);

        if ($newContent !== $content) {
            $content = $newContent;
            $changes++;
            echo "✅ Added show_conversion_options => true to {$slug}\n";
        }
    }
}

foreach ($needsShowFalse as $slug) {
    // Pattern to find the conversion config and add show_conversion_options before 'limits'
    $pattern = "/('{$slug}' => \[[^\]]*?'job' => 'generic_convert_to_pdf',\s*\n)/";

    if (preg_match($pattern, $content)) {
        $replacement = "$1        'show_conversion_options' => false,\n";
        $newContent = preg_replace($pattern, $replacement, $content);

        if ($newContent !== $content) {
            $content = $newContent;
            $changes++;
            echo "✅ Added show_conversion_options => false to {$slug}\n";
        }
    }
}

if ($changes > 0) {
    // Backup original file
    copy($landingPagesPath, $landingPagesPath.'.backup');
    echo "\n📦 Created backup: {$landingPagesPath}.backup\n";

    // Write changes
    file_put_contents($landingPagesPath, $content);
    echo "\n✨ Successfully updated {$changes} conversions in landing_pages.php\n";
    echo "Run 'php check-conversions.php' to verify.\n";
} else {
    echo "❌ No changes made. Pattern matching may need adjustment.\n";
}
