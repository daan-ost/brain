<?php

require __DIR__.'/vendor/autoload.php';

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

\ConvertApi\ConvertApi::setApiSecret($_ENV['CONVERTAPI_SECRET']);

echo "Testing PDF to HTML conversion with parameters...\n\n";

// Test PDF file path
$testPdfPath = __DIR__.'/../testfiles/pdf/test.pdf';

if (! file_exists($testPdfPath)) {
    exit("Test PDF file not found at: $testPdfPath\n");
}

echo "Using test file: $testPdfPath\n\n";

// Test 1: Without parameters (baseline)
echo "Test 1: PDF to HTML without parameters\n";
echo "----------------------------------------\n";
try {
    $result = \ConvertApi\ConvertApi::convert('html', [
        'File' => $testPdfPath,
    ], 'pdf');

    $files = $result->getFiles();
    echo "✓ Conversion successful\n";
    echo '  Files created: '.count($files)."\n";
    foreach ($files as $file) {
        echo '  - '.$file->getFileName()."\n";
    }
} catch (\Exception $e) {
    echo '✗ Error: '.$e->getMessage()."\n";
}
echo "\n";

// Test 2: With ImageFormat parameter
echo "Test 2: PDF to HTML with ImageFormat=jpg\n";
echo "----------------------------------------\n";
try {
    $result = \ConvertApi\ConvertApi::convert('html', [
        'File' => $testPdfPath,
        'ImageFormat' => 'jpg',
    ], 'pdf');

    $files = $result->getFiles();
    echo "✓ Conversion successful\n";
    echo '  Files created: '.count($files)."\n";
    foreach ($files as $file) {
        echo '  - '.$file->getFileName()."\n";
    }
} catch (\Exception $e) {
    echo '✗ Error: '.$e->getMessage()."\n";
}
echo "\n";

// Test 3: With all parameters
echo "Test 3: PDF to HTML with all parameters\n";
echo "----------------------------------------\n";
try {
    $result = \ConvertApi\ConvertApi::convert('html', [
        'File' => $testPdfPath,
        'ExtractMode' => 'simple',
        'IncludeImages' => true,
        'ImageFormat' => 'jpg',
    ], 'pdf');

    $files = $result->getFiles();
    echo "✓ Conversion successful\n";
    echo '  Files created: '.count($files)."\n";
    foreach ($files as $file) {
        echo '  - '.$file->getFileName()."\n";
    }
} catch (\Exception $e) {
    echo '✗ Error: '.$e->getMessage()."\n";
}
echo "\n";

// Test 4: Check what parameters ConvertAPI actually accepts
echo "Test 4: Testing parameter variations\n";
echo "----------------------------------------\n";

$parameterTests = [
    ['ImageFormat' => 'jpeg'],
    ['ImageFormat' => 'JPEG'],
    ['ImgFormat' => 'jpg'],
    ['imageformat' => 'jpg'],
];

foreach ($parameterTests as $i => $params) {
    $paramStr = json_encode($params);
    echo 'Attempt '.($i + 1).": $paramStr\n";
    try {
        $result = \ConvertApi\ConvertApi::convert('html', array_merge([
            'File' => $testPdfPath,
        ], $params), 'pdf');
        echo "  ✓ Accepted\n";
    } catch (\Exception $e) {
        echo '  ✗ Rejected: '.$e->getMessage()."\n";
    }
}

echo "\nTest complete.\n";
