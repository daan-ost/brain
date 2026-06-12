<?php

namespace Tests\Integration;

use App\Services\ConvertApiService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Integration tests for ConvertAPI parameter mappings
 *
 * These tests call the REAL ConvertAPI service and consume credits.
 * They are excluded from normal test runs via the @group annotation.
 *
 * Run manually with: php artisan test --group=convertapi
 *
 * @group convertapi
 */
class ConvertApiIntegrationTest extends TestCase
{
    protected ConvertApiService $convertApiService;

    protected string $fixturesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->convertApiService = app(ConvertApiService::class);
        $this->fixturesPath = base_path('tests/fixtures');

        // Skip tests if ConvertAPI is not configured
        if (empty(config('services.convertapi.secret'))) {
            $this->markTestSkipped('ConvertAPI secret not configured');
        }
    }

    /**
     * Helper to get a test fixture file path
     */
    protected function getFixturePath(string $filename): string
    {
        $path = $this->fixturesPath.'/'.$filename;

        if (! file_exists($path)) {
            $this->markTestSkipped("Fixture file not found: {$filename}. Create it in tests/fixtures/");
        }

        return $path;
    }

    /**
     * Helper to clean up temporary files
     */
    protected function cleanupTempFile(?string $path): void
    {
        if ($path && file_exists($path)) {
            unlink($path);
        }
    }

    // ========================================================================
    // HTML to PDF Tests
    // ========================================================================

    #[Test]
    public function html_to_pdf_with_all_options(): void
    {
        $htmlContent = '<html><body><h1>Test Document</h1><p>This is a test.</p></body></html>';
        $tempFile = tempnam(sys_get_temp_dir(), 'test_').'.html';
        file_put_contents($tempFile, $htmlContent);

        try {
            $result = $this->convertApiService->convertWithParameters(
                $tempFile,
                'html',
                'pdf',
                [
                    'PageSize' => 'a4',
                    'PageOrientation' => 'landscape',
                    'Scale' => 100,
                    'MarginTop' => 10,
                    'MarginBottom' => 10,
                    'MarginLeft' => 10,
                    'MarginRight' => 10,
                ]
            );

            $this->assertNotNull($result);
            $this->assertNotNull($result->getFile());
            $this->assertGreaterThan(0, $result->getFile()->getSize());
        } finally {
            $this->cleanupTempFile($tempFile);
        }
    }

    // ========================================================================
    // Excel to PDF Tests
    // ========================================================================

    #[Test]
    public function excel_to_pdf_with_corrected_mappings(): void
    {
        $fixturePath = $this->getFixturePath('test.xlsx');

        $result = $this->convertApiService->convertWithParameters(
            $fixturePath,
            'xlsx',
            'pdf',
            [
                'PageSize' => 'a4',
                'PageOrientation' => 'landscape',
                'AutoPageFit' => true,  // Corrected from FitToPage
                'AutoColumnFit' => true, // Corrected from AutoFit
                'WorksheetIndex' => 1,
            ]
        );

        $this->assertNotNull($result);
        $this->assertNotNull($result->getFile());
        $this->assertGreaterThan(0, $result->getFile()->getSize());
    }

    // ========================================================================
    // PowerPoint to PDF Tests
    // ========================================================================

    #[Test]
    public function powerpoint_to_pdf_with_corrected_mappings(): void
    {
        $fixturePath = $this->getFixturePath('test.pptx');

        $result = $this->convertApiService->convertWithParameters(
            $fixturePath,
            'pptx',
            'pdf',
            [
                'PageRange' => '1-5',  // Corrected from SlideRange
                'ConvertSpeakerNotes' => 'Disabled',
                'ConvertHiddenSlides' => false, // Corrected from IncludeHiddenSlides
            ]
        );

        $this->assertNotNull($result);
        $this->assertNotNull($result->getFile());
        $this->assertGreaterThan(0, $result->getFile()->getSize());
    }

    // ========================================================================
    // Images to PDF Tests
    // ========================================================================

    #[Test]
    public function images_to_pdf_with_corrected_mappings(): void
    {
        $fixturePath = $this->getFixturePath('test.jpg');

        $result = $this->convertApiService->convertWithParameters(
            $fixturePath,
            'jpg',
            'pdf',
            [
                'PageSize' => 'a4',
                'PageOrientation' => 'portrait',
                'MarginHorizontal' => 10, // Corrected from HorizontalMargin
                'MarginVertical' => 10,   // Corrected from VerticalMargin
                'CenterImage' => true,
            ]
        );

        $this->assertNotNull($result);
        $this->assertNotNull($result->getFile());
        $this->assertGreaterThan(0, $result->getFile()->getSize());
    }

    // ========================================================================
    // PDF to Word Tests (OCR)
    // ========================================================================

    #[Test]
    public function pdf_to_word_with_corrected_ocr_mode(): void
    {
        $fixturePath = $this->getFixturePath('test.pdf');

        $result = $this->convertApiService->convertWithParameters(
            $fixturePath,
            'pdf',
            'docx',
            [
                'OcrMode' => 'auto',  // Corrected from Ocr boolean
                'OcrLanguage' => 'auto',
                'PageRange' => '1-3',
                'Wysiwyg' => true,    // Exact formatting - prevents header/footer misinterpretation
            ]
        );

        $this->assertNotNull($result);
        $this->assertNotNull($result->getFile());
        $this->assertGreaterThan(0, $result->getFile()->getSize());
    }

    // ========================================================================
    // PDF to Excel Tests (OCR)
    // ========================================================================

    #[Test]
    public function pdf_to_excel_with_corrected_ocr_mode(): void
    {
        $fixturePath = $this->getFixturePath('test.pdf');

        $result = $this->convertApiService->convertWithParameters(
            $fixturePath,
            'pdf',
            'xlsx',
            [
                'OcrMode' => 'auto',  // Corrected from Ocr boolean
                'OcrLanguage' => 'auto',
            ]
        );

        $this->assertNotNull($result);
        $this->assertNotNull($result->getFile());
        $this->assertGreaterThan(0, $result->getFile()->getSize());
    }

    // ========================================================================
    // PDF to Text Tests
    // ========================================================================

    #[Test]
    public function pdf_to_text_with_corrected_mappings(): void
    {
        $fixturePath = $this->getFixturePath('test.pdf');

        $result = $this->convertApiService->convertWithParameters(
            $fixturePath,
            'pdf',
            'txt',
            [
                'IncludeFormatting' => true,  // Corrected from PreserveFormatting
                'OcrMode' => 'auto',          // Corrected from Ocr boolean
                'SplitPages' => false,        // Corrected from IncludePageBreaks
            ]
        );

        $this->assertNotNull($result);
        $this->assertNotNull($result->getFile());
    }

    // ========================================================================
    // Compress PDF Tests
    // ========================================================================

    #[Test]
    public function compress_pdf_with_corrected_preset(): void
    {
        $fixturePath = $this->getFixturePath('test.pdf');

        $result = $this->convertApiService->convertWithParameters(
            $fixturePath,
            'pdf',
            'compress',
            [
                'Preset' => 'web',  // Corrected from CompressionLevel
                'ImageQuality' => 80,
                'ImageResolution' => 150,
            ]
        );

        $this->assertNotNull($result);
        $this->assertNotNull($result->getFile());
        $this->assertGreaterThan(0, $result->getFile()->getSize());
    }

    // ========================================================================
    // OCR PDF Tests
    // ========================================================================

    #[Test]
    public function ocr_pdf_with_corrected_ocr_mode(): void
    {
        $fixturePath = $this->getFixturePath('test.pdf');

        $result = $this->convertApiService->convertWithParameters(
            $fixturePath,
            'pdf',
            'ocr',
            [
                'OcrMode' => 'auto',  // Corrected from speed/balanced/quality
                'OcrLanguage' => 'auto',
            ]
        );

        $this->assertNotNull($result);
        $this->assertNotNull($result->getFile());
        $this->assertGreaterThan(0, $result->getFile()->getSize());
    }

    // ========================================================================
    // PDF Delete Pages Tests
    // ========================================================================

    #[Test]
    public function pdf_delete_pages_with_correct_endpoint(): void
    {
        $fixturePath = $this->getFixturePath('test.pdf');

        // This test verifies the endpoint fix: pdf -> delete-pages (not pdf -> pdf)
        $result = $this->convertApiService->convertWithParameters(
            $fixturePath,
            'pdf',
            'delete-pages',  // Corrected endpoint
            [
                'PageRange' => '1',
            ]
        );

        $this->assertNotNull($result);
        $this->assertNotNull($result->getFile());
        $this->assertGreaterThan(0, $result->getFile()->getSize());
    }

    // ========================================================================
    // CSV to PDF Tests
    // ========================================================================

    #[Test]
    public function csv_to_pdf_with_corrected_mapping(): void
    {
        $csvContent = "Name,Age,City\nJohn,30,Amsterdam\nJane,25,Rotterdam";
        $tempFile = tempnam(sys_get_temp_dir(), 'test_').'.csv';
        file_put_contents($tempFile, $csvContent);

        try {
            $result = $this->convertApiService->convertWithParameters(
                $tempFile,
                'csv',
                'pdf',
                [
                    'PageSize' => 'a4',
                    'PageOrientation' => 'portrait',
                    'AutoPageFit' => true,  // Corrected from FitToPage
                    'Delimiter' => ',',
                    'HasHeaderRow' => true,
                ]
            );

            $this->assertNotNull($result);
            $this->assertNotNull($result->getFile());
            $this->assertGreaterThan(0, $result->getFile()->getSize());
        } finally {
            $this->cleanupTempFile($tempFile);
        }
    }

    // ========================================================================
    // Word to PDF Tests
    // ========================================================================

    #[Test]
    public function word_to_pdf_basic_conversion(): void
    {
        $fixturePath = $this->getFixturePath('test.docx');

        $result = $this->convertApiService->convertWithParameters(
            $fixturePath,
            'docx',
            'pdf',
            [
                'ConvertHeadings' => true,
                'ConvertMetadata' => true,
                'ConvertBookmarks' => true,
                'Pdfa' => false,
            ]
        );

        $this->assertNotNull($result);
        $this->assertNotNull($result->getFile());
        $this->assertGreaterThan(0, $result->getFile()->getSize());
    }

    // ========================================================================
    // PDF Rotate Tests
    // ========================================================================

    #[Test]
    public function pdf_rotate_with_correct_parameters(): void
    {
        $fixturePath = $this->getFixturePath('test.pdf');

        $result = $this->convertApiService->convertWithParameters(
            $fixturePath,
            'pdf',
            'rotate',
            [
                'RotateAngle' => 90,
                'PageRange' => '1',
            ]
        );

        $this->assertNotNull($result);
        $this->assertNotNull($result->getFile());
        $this->assertGreaterThan(0, $result->getFile()->getSize());
    }
}
