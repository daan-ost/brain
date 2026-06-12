#!/bin/bash

cd /Users/daanvantongeren/Documents/Sites/basewebsite/www

echo "Testing ConvertAPI PDF to HTML parameters..."
echo ""

php artisan tinker --execute="
\$service = app('App\Services\ConvertApiService');

// Get test PDF
\$testPdf = '/Users/daanvantongeren/Documents/Sites/basewebsite/testfiles/pdf/1.pdf';

if (!file_exists(\$testPdf)) {
    echo 'Test PDF not found\n';
    exit;
}

echo 'Test 1: Without parameters\n';
echo str_repeat('-', 40) . '\n';
try {
    \$result = \$service->convertWithParameters(\$testPdf, 'pdf', 'html', []);
    echo '✓ Success - no parameters\n';
} catch (\Exception \$e) {
    echo '✗ Error: ' . \$e->getMessage() . '\n';
}

echo '\n';
echo 'Test 2: With ImageFormat=jpg\n';
echo str_repeat('-', 40) . '\n';
try {
    \$result = \$service->convertWithParameters(\$testPdf, 'pdf', 'html', [
        'ImageFormat' => 'jpg'
    ]);
    echo '✓ Success - ImageFormat accepted\n';
} catch (\Exception \$e) {
    echo '✗ Error: ' . \$e->getMessage() . '\n';
}

echo '\n';
echo 'Test 3: With all pdf_to_html parameters\n';
echo str_repeat('-', 40) . '\n';
try {
    \$result = \$service->convertWithParameters(\$testPdf, 'pdf', 'html', [
        'ExtractMode' => 'simple',
        'IncludeImages' => true,
        'ImageFormat' => 'jpg'
    ]);
    echo '✓ Success - all parameters accepted\n';
} catch (\Exception \$e) {
    echo '✗ Error: ' . \$e->getMessage() . '\n';
}

echo '\nTests complete.\n';
"
