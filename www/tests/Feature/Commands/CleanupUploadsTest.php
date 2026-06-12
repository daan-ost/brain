<?php

namespace Tests\Feature\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CleanupUploadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['cleanup.upload_retention_minutes' => 60]);
    }

    public function test_deletes_old_upload_files(): void
    {
        // Create old file (2 hours ago)
        Storage::put('uploads/old-file.pdf', 'content');
        $this->setFileModificationTime('uploads/old-file.pdf', now()->subHours(2));

        // Create recent file (30 minutes ago)
        Storage::put('uploads/recent-file.pdf', 'content');
        $this->setFileModificationTime('uploads/recent-file.pdf', now()->subMinutes(30));

        $this->artisan('cleanup:uploads')
            ->assertSuccessful();

        Storage::assertMissing('uploads/old-file.pdf');
        Storage::assertExists('uploads/recent-file.pdf');
    }

    public function test_dry_run_does_not_delete_files(): void
    {
        Storage::put('uploads/old-file.pdf', 'content');
        $this->setFileModificationTime('uploads/old-file.pdf', now()->subHours(2));

        $this->artisan('cleanup:uploads', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('DRY RUN MODE');

        Storage::assertExists('uploads/old-file.pdf');
    }

    public function test_skips_hidden_files(): void
    {
        Storage::put('uploads/.gitignore', 'content');
        $this->setFileModificationTime('uploads/.gitignore', now()->subHours(2));

        $this->artisan('cleanup:uploads')
            ->assertSuccessful();

        Storage::assertExists('uploads/.gitignore');
    }

    public function test_handles_nested_directories(): void
    {
        Storage::put('uploads/user_1/old-file.pdf', 'content');
        $this->setFileModificationTime('uploads/user_1/old-file.pdf', now()->subHours(2));

        Storage::put('uploads/user_2/recent-file.pdf', 'content');
        $this->setFileModificationTime('uploads/user_2/recent-file.pdf', now()->subMinutes(30));

        $this->artisan('cleanup:uploads')
            ->assertSuccessful();

        Storage::assertMissing('uploads/user_1/old-file.pdf');
        Storage::assertExists('uploads/user_2/recent-file.pdf');
    }

    public function test_removes_empty_directories(): void
    {
        Storage::put('uploads/empty_dir/old-file.pdf', 'content');
        $this->setFileModificationTime('uploads/empty_dir/old-file.pdf', now()->subHours(2));

        $this->artisan('cleanup:uploads')
            ->assertSuccessful();

        Storage::assertMissing('uploads/empty_dir/old-file.pdf');
        $this->assertEmpty(Storage::directories('uploads'));
    }

    public function test_reports_deleted_count_and_size(): void
    {
        Storage::put('uploads/file1.pdf', str_repeat('x', 1024));
        Storage::put('uploads/file2.pdf', str_repeat('x', 2048));
        $this->setFileModificationTime('uploads/file1.pdf', now()->subHours(2));
        $this->setFileModificationTime('uploads/file2.pdf', now()->subHours(2));

        $this->artisan('cleanup:uploads')
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
