<?php

/**
 * POC 4 - Table of Contents & Bookmarks Demo Script
 *
 * This script demonstrates the full TOC + bookmarks functionality
 * with manually created test PDFs.
 *
 * Usage: php test-toc-poc4.php
 */

require __DIR__.'/vendor/autoload.php';

use App\Services\CoverPageGeneratorService;
use App\Services\PdfStructure\DocumentStructureTracker;
use setasign\Fpdi\Fpdi;

// Bootstrap Laravel
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "========================================\n";
echo "POC 4: TOC & Bookmarks Demo\n";
echo "========================================\n\n";

// Create output directory
$outputDir = storage_path('app/test-toc-demo');
if (! file_exists($outputDir)) {
    mkdir($outputDir, 0755, true);
}

echo "Step 1: Creating test PDFs...\n";

// Create 3 test PDFs with different page counts
$testDocs = [
    ['title' => 'Annual Report 2024', 'pages' => 3, 'color' => [52, 152, 219]],
    ['title' => 'Financial Overview', 'pages' => 5, 'color' => [46, 204, 113]],
    ['title' => 'Meeting Minutes', 'pages' => 2, 'color' => [231, 76, 60]],
];

$testDocPaths = [];

foreach ($testDocs as $index => $doc) {
    $pdf = new Fpdi;

    for ($page = 1; $page <= $doc['pages']; $page++) {
        $pdf->AddPage();
        $pdf->SetFillColor(...$doc['color']);
        $pdf->Rect(20, 20, 170, 250, 'F');

        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 24);
        $pdf->SetXY(20, 120);
        $pdf->Cell(170, 10, $doc['title'], 0, 1, 'C');

        $pdf->SetFont('Arial', '', 16);
        $pdf->SetXY(20, 135);
        $pdf->Cell(170, 10, "Page $page of {$doc['pages']}", 0, 1, 'C');
    }

    $filename = 'test-doc-'.($index + 1).'.pdf';
    $filepath = $outputDir.'/'.$filename;
    $pdf->Output('F', $filepath);
    $testDocPaths[] = $filepath;

    echo "  ✓ Created: $filename ({$doc['pages']} pages)\n";
}

echo "\nStep 2: Merging test PDFs...\n";

// Merge all test docs into one
$mergedPdf = new Fpdi;

foreach ($testDocPaths as $path) {
    $pageCount = $mergedPdf->setSourceFile($path);
    for ($i = 1; $i <= $pageCount; $i++) {
        $template = $mergedPdf->importPage($i);
        $size = $mergedPdf->getTemplateSize($template);
        $mergedPdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $mergedPdf->useTemplate($template);
    }
}

$mergedPath = $outputDir.'/merged-content.pdf';
$mergedPdf->Output('F', $mergedPath);
echo "  ✓ Merged PDF created: merged-content.pdf (10 pages total)\n";

echo "\nStep 3: Analyzing document structure...\n";

// Use DocumentStructureTracker to analyze structure
$tracker = new DocumentStructureTracker(1); // Cover will be page 1

foreach ($testDocs as $index => $doc) {
    $tracker->addDocument($doc['title'], $testDocPaths[$index]);
}

$documentStructure = $tracker->getDocuments();

echo "  Document Structure:\n";
foreach ($documentStructure as $doc) {
    echo "    - {$doc['title']}: pages {$doc['start_page']}-{$doc['end_page']} ({$doc['page_count']} pages)\n";
}

echo "\nStep 4: Generating cover page with TOC...\n";

// Create template configuration
$templateConfig = [
    'preset' => 'business',
    'cover' => [
        'title' => 'Document Bundle 2024',
        'subtitle' => 'Generated with POC 4',
        'description' => 'This PDF demonstrates the Table of Contents and Bookmarks functionality.',
        'logo_storage_path' => null,
        'logo_alignment' => 'right',
    ],
    'toc' => [
        'show_toc' => true,
        'toc_on_new_page' => false, // TOC on same page as cover
        'toc_title' => 'Table of Contents',
        'toc_title_font' => 'Arial',
        'toc_title_fontsize' => 18,
        'toc_title_color' => '#5569AD',
        'toc_entry_font' => 'Arial',
        'toc_entry_fontsize' => 11,
        'toc_entry_color' => '#333333',
        'toc_show_page_numbers' => true,
    ],
    'language' => 'en',
];

// Generate cover with TOC
$coverGenerator = new CoverPageGeneratorService;
$coverPath = $outputDir.'/cover.pdf';

// Save cover directly to file
$pdf = new Fpdi;
$pdf->SetAutoPageBreak(false);
$pdf->SetMargins(20, 20, 20);
$pdf->AddPage();

// Render cover content
$pdf->SetFont('Arial', 'B', 28);
$pdf->SetTextColor(85, 105, 173);
$pdf->SetXY(20, 80);
$pdf->Cell(170, 12, $templateConfig['cover']['title'], 0, 1, 'C');

$pdf->SetFont('Arial', '', 14);
$pdf->SetTextColor(100, 100, 100);
$pdf->SetXY(20, 95);
$pdf->Cell(170, 8, $templateConfig['cover']['subtitle'], 0, 1, 'C');

$pdf->SetFont('Arial', '', 10);
$pdf->SetXY(20, 105);
$pdf->MultiCell(170, 5, $templateConfig['cover']['description'], 0, 'C');

// Add accent bar
$pdf->SetFillColor(85, 105, 173);
$pdf->Rect(20, 120, 170, 3, 'F');

// Add TOC
$yPos = 135;
$pdf->SetFont('Arial', 'B', 18);
$pdf->SetTextColor(85, 105, 173);
$pdf->SetXY(20, $yPos);
$pdf->Cell(170, 10, $templateConfig['toc']['toc_title'], 0, 1, 'L');

$yPos = $pdf->GetY() + 5;

// Render TOC entries
$pdf->SetFont('Arial', '', 11);
$pdf->SetTextColor(51, 51, 51);

foreach ($documentStructure as $doc) {
    $pdf->SetY($yPos);

    // Bullet
    $bullet = '□ ';
    $bulletWidth = $pdf->GetStringWidth($bullet);
    $pdf->SetX(20);
    $pdf->Cell($bulletWidth, 6, $bullet, 0, 0, 'L');

    // Title
    $pdf->Cell(135, 6, $doc['title'], 0, 0, 'L');

    // Page number
    $pdf->Cell(15, 6, 'Page '.$doc['start_page'], 0, 1, 'R');

    $yPos = $pdf->GetY() + 2;
}

$pdf->Output('F', $coverPath);
echo "  ✓ Cover with TOC created: cover.pdf\n";

echo "\nStep 5: Merging cover with content using SetaPDF-Merger...\n";

// Load cover as SetaPDF document
$document = SetaPDF_Core_Document::load(
    new SetaPDF_Core_Reader_File($coverPath)
);

// Set up writer
$finalPath = $outputDir.'/final-with-toc-and-bookmarks.pdf';
$writer = new SetaPDF_Core_Writer_File($finalPath);
$document->setWriter($writer);

// Initialize merger
$merger = new SetaPDF_Merger($document);

// Add merged content
$merger->addFile($mergedPath);

// Merge
$merger->merge();

echo "  ✓ PDFs merged with SetaPDF-Merger\n";

echo "\nStep 6: Adding bookmarks/outlines...\n";

// Get outlines catalog
$outlines = $document->getCatalog()->getOutlines();
$wrapper = new \App\Services\Pdf\PDFenBookmarkWrapper($outlines);

// Add bookmark for cover
$coverTitle = $templateConfig['cover']['title'];
$destination = SetaPDF_Core_Document_Destination::createByPageNo($document, 1);
$bookmarkCover = SetaPDF_Core_Document_OutlinesItem::create(
    $document,
    $coverTitle,
    [SetaPDF_Core_Document_OutlinesItem::DEST => $destination]
);
$outlines[] = $bookmarkCover;

// Add bookmarks for each document
foreach ($documentStructure as $doc) {
    $title = '□ '.$doc['title'];
    $destination = SetaPDF_Core_Document_Destination::createByPageNo($document, $doc['start_page']);
    $item = SetaPDF_Core_Document_OutlinesItem::create(
        $document,
        $title,
        [SetaPDF_Core_Document_OutlinesItem::DEST => $destination]
    );
    $wrapper->addOutline($item, 0);
}

// Close all outlines and auto-show bookmarks panel
foreach ($document->getCatalog()->getOutlines() as $outline) {
    $outline->close();
}
$document->getCatalog()->setPageMode(SetaPDF_Core_Document_PageMode::USE_OUTLINES);

echo '  ✓ Bookmarks added ('.count($documentStructure)." documents)\n";

echo "\nStep 7: Saving final PDF...\n";

// Save
$document->save()->finish();

echo "  ✓ Final PDF saved: final-with-toc-and-bookmarks.pdf\n";

echo "\n========================================\n";
echo "✅ POC 4 Demo Complete!\n";
echo "========================================\n\n";

echo "Output files in: $outputDir/\n\n";

echo "Files created:\n";
echo "  1. test-doc-1.pdf (3 pages) - Annual Report 2024\n";
echo "  2. test-doc-2.pdf (5 pages) - Financial Overview\n";
echo "  3. test-doc-3.pdf (2 pages) - Meeting Minutes\n";
echo "  4. merged-content.pdf (10 pages) - All docs merged\n";
echo "  5. cover.pdf (1 page) - Cover with TOC\n";
echo "  6. final-with-toc-and-bookmarks.pdf (11 pages) - FINAL RESULT ⭐\n\n";

echo "Open the final PDF to see:\n";
echo "  ✓ Cover page with Table of Contents\n";
echo "  ✓ TOC entries with page numbers\n";
echo "  ✓ Bookmarks panel (should auto-open)\n";
echo "  ✓ Clickable bookmarks to navigate\n\n";

echo 'Total pages: '.($tracker->getTotalPages() + 1)." (1 cover + {$tracker->getTotalPages()} content)\n";
echo "\n";
