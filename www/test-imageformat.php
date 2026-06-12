<?php

require __DIR__.'/vendor/autoload.php';

// Load .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Set API credentials
\ConvertApi\ConvertApi::setApiCredentials($_ENV['CONVERTAPI_SECRET']);
\ConvertApi\ConvertApi::setApiBase('https://eu-v2.convertapi.com/');

$api = new \ConvertApi\ConvertApi;

$testPdf = '/Users/daanvantongeren/Documents/Sites/basewebsite/testfiles/pdf/1.pdf';

echo "Testing PDF to HTML with different ImageFormat values...\n\n";

// Test 1: No parameter
echo "Test 1: No ImageFormat parameter\n";
echo str_repeat('-', 50)."\n";
try {
    $result = $api->convert('html', [
        'File' => $testPdf,
    ], 'pdf');

    $html = $result->getFile()->getContents();
    if (strpos($html, 'image/png') !== false) {
        echo "✓ Result contains PNG images\n";
    } elseif (strpos($html, 'image/jpeg') !== false || strpos($html, 'image/jpg') !== false) {
        echo "✓ Result contains JPG images\n";
    } else {
        echo "? No embedded images found\n";
    }
} catch (\Exception $e) {
    echo '✗ Error: '.$e->getMessage()."\n";
}
echo "\n";

// Test 2: ImageFormat=jpg
echo "Test 2: ImageFormat=jpg\n";
echo str_repeat('-', 50)."\n";
try {
    $result = $api->convert('html', [
        'File' => $testPdf,
        'ImageFormat' => 'jpg',
    ], 'pdf');

    $html = $result->getFile()->getContents();
    if (strpos($html, 'image/png') !== false) {
        echo "✗ Result STILL contains PNG images (parameter ignored!)\n";
    } elseif (strpos($html, 'image/jpeg') !== false || strpos($html, 'image/jpg') !== false) {
        echo "✓ Result contains JPG images (parameter worked!)\n";
    } else {
        echo "? No embedded images found\n";
    }
} catch (\Exception $e) {
    echo '✗ Error: '.$e->getMessage()."\n";
}
echo "\n";

// Test 3: ImgFormat=jpg (different parameter name)
echo "Test 3: ImgFormat=jpg (alternative name)\n";
echo str_repeat('-', 50)."\n";
try {
    $result = $api->convert('html', [
        'File' => $testPdf,
        'ImgFormat' => 'jpg',
    ], 'pdf');

    $html = $result->getFile()->getContents();
    if (strpos($html, 'image/png') !== false) {
        echo "✗ Result contains PNG images\n";
    } elseif (strpos($html, 'image/jpeg') !== false || strpos($html, 'image/jpg') !== false) {
        echo "✓ Result contains JPG images (parameter worked!)\n";
    } else {
        echo "? No embedded images found\n";
    }
} catch (\Exception $e) {
    echo '✗ Error: '.$e->getMessage()."\n";
}
echo "\n";

echo "Test complete.\n";
