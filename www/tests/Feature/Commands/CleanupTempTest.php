<?php

namespace Tests\Feature\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CleanupTempTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['cleanup.temp_hours' => 1]);
    }

    public function test_deletes_old_temp_files(): void
    {
        // Create old temp file (2 hours ago)
        Storage::put('temp/old-extract/file.pdf', 'content');
        $this->setFileModificationTime('temp/old-extract/file.pdf', now()->subHours(2));

        // Create recent temp file (30 minutes ago)
        Storage::put('temp/recent-extract/file.pdf', 'content');
        $this->setFileModificationTime('temp/recent-extract/file.pdf', now()->subMinutes(30));

        $this->artisan('cleanup:temp')
            ->assertSuccessful();

        Storage::assertMissing('temp/old-extract/file.pdf');
        Storage::assertExists('temp/recent-extract/file.pdf');
    }

    public function test_deletes_old_cover_page_temp_files(): void
    {
        // Create old cover temp file
        Storage::put('temp/cover/user_1/batch_123/cover.pdf', 'content');
        $this->setFileModificationTime('temp/cover/user_1/batch_123/cover.pdf', now()->subHours(2));

        $this->artisan('cleanup:temp')
            ->assertSuccessful();

        Storage::assertMissing('temp/cover/user_1/batch_123/cover.pdf');
    }

    public function test_removes_empty_directories(): void
    {
        Storage::put('temp/empty_extract/old-file.pdf', 'content');
        $this->setFileModificationTime('temp/empty_extract/old-file.pdf', now()->subHours(2));

        $this->artisan('cleanup:temp')
            ->assertSuccessful();

        Storage::assertMissing('temp/empty_extract/old-file.pdf');
        $this->assertNotContains('temp/empty_extract', Storage::directories('temp'));
    }

    public function test_dry_run_does_not_delete_files(): void
    {
        Storage::put('temp/extract/old-file.pdf', 'content');
        $this->setFileModificationTime('temp/extract/old-file.pdf', now()->subHours(2));

        $this->artisan('cleanup:temp', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('DRY RUN MODE');

        Storage::assertExists('temp/extract/old-file.pdf');
    }

    public function test_skips_hidden_files(): void
    {
        Storage::put('temp/.gitkeep', '');
        $this->setFileModificationTime('temp/.gitkeep', now()->subHours(2));

        $this->artisan('cleanup:temp')
            ->assertSuccessful();

        Storage::assertExists('temp/.gitkeep');
    }

    public function test_handles_various_temp_file_types(): void
    {
        // Various extraction temp directories
        Storage::put('temp/docx_extract_abc123/document.docx', 'content');
        Storage::put('temp/merge_extract_def456/file1.pdf', 'content');
        Storage::put('temp/convert_extract_ghi789/output.pdf', 'content');

        $this->setFileModificationTime('temp/docx_extract_abc123/document.docx', now()->subHours(2));
        $this->setFileModificationTime('temp/merge_extract_def456/file1.pdf', now()->subHours(2));
        $this->setFileModificationTime('temp/convert_extract_ghi789/output.pdf', now()->subHours(2));

        $this->artisan('cleanup:temp')
            ->assertSuccessful();

        Storage::assertMissing('temp/docx_extract_abc123/document.docx');
        Storage::assertMissing('temp/merge_extract_def456/file1.pdf');
        Storage::assertMissing('temp/convert_extract_ghi789/output.pdf');
    }

    public function test_reports_deleted_count(): void
    {
        Storage::put('temp/file1.pdf', 'content');
        Storage::put('temp/file2.pdf', 'content');
        $this->setFileModificationTime('temp/file1.pdf', now()->subHours(2));
        $this->setFileModificationTime('temp/file2.pdf', now()->subHours(2));

        $this->artisan('cleanup:temp')
            ->assertSuccessful()
            ->expectsOutputToContain('Deleted: 2 file(s)');
    }

    /**
     * Helper to set file modification time in fake storage.
     */
    private function setFileModificationTime(string $path, $time): void
    {
        $fullPath = Storage::path($path);
        touch($fullPath, $time->timestamp);
    }
}
