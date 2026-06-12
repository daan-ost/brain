<?php

require __DIR__.'/vendor/autoload.php';

use ZBateson\MailMimeParser\MailMimeParser;

echo "=== Testing Email Parser ===\n\n";

// Find a test EML file (MSG files use different format)
$testFiles = glob(__DIR__.'/../testfiles/mail/*.eml');
if (empty($testFiles)) {
    echo "❌ No .eml test files found\n";
    echo "MSG files require different parser - use EML for testing\n";
    exit(1);
}

if (empty($testFiles)) {
    echo "❌ No test files found in testfiles/mail/\n";
    echo "Please add a .msg or .eml file to testfiles/mail/ first\n";
    exit(1);
}

$testFile = $testFiles[0];
echo '📧 Testing with: '.basename($testFile)."\n\n";

try {
    $parser = new MailMimeParser;
    $handle = fopen($testFile, 'r');

    if (! $handle) {
        throw new Exception('Failed to open file');
    }

    echo "✅ File opened successfully\n";

    $message = $parser->parse($handle, false);

    echo "✅ Email parsed successfully\n\n";

    // Extract headers
    $from = $message->getHeaderValue('from');
    $to = $message->getHeaderValue('to');
    $subject = $message->getHeaderValue('subject');
    $date = $message->getHeaderValue('date');

    echo "📨 Email Details:\n";
    echo '  From: '.($from ?: 'N/A')."\n";
    echo '  To: '.($to ?: 'N/A')."\n";
    echo '  Subject: '.($subject ?: 'N/A')."\n";
    echo '  Date: '.($date ?: 'N/A')."\n\n";

    // Extract body (do this BEFORE closing handle!)
    $htmlPart = $message->getHtmlPart();
    $textPart = $message->getTextPart();

    echo "📄 Body:\n";
    echo '  HTML: '.($htmlPart ? '✅ Found ('.strlen($htmlPart->getContent()).' bytes)' : '❌ Not found')."\n";
    echo '  Text: '.($textPart ? '✅ Found ('.strlen($textPart->getContent()).' bytes)' : '❌ Not found')."\n\n";

    // Extract attachments
    $attachments = $message->getAllAttachmentParts();
    echo '📎 Attachments: '.count($attachments)."\n";

    foreach ($attachments as $i => $attachment) {
        $filename = $attachment->getHeaderParameter('content-disposition', 'filename') ?: 'attachment';
        $contentType = $attachment->getHeaderValue('content-type');
        $size = strlen($attachment->getContent());

        echo '  '.($i + 1).'. '.$filename.' ('.$contentType.', '.number_format($size)." bytes)\n";
    }

    // Now safe to close handle after all parsing is done
    fclose($handle);

    echo "\n✅ ALL TESTS PASSED!\n";

} catch (Exception $e) {
    echo "\n❌ ERROR: ".$e->getMessage()."\n";
    echo "\nStack trace:\n".$e->getTraceAsString()."\n";
    exit(1);
}
