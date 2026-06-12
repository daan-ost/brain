<?php

namespace App\Console\Commands;

use App\Services\RetentionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupUploads extends Command
{
    protected $signature = 'cleanup:uploads {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Remove uploaded files older than 1 hour';

    public function handle(RetentionService $retentionService): int
    {
        $this->info('Starting upload cleanup...');

        $cutoffDate = $retentionService->getUploadCutoffDate();
        $uploadsPath = config('cleanup.paths.uploads', 'uploads');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No files will be deleted');
        }

        $this->info("Cutoff date: {$cutoffDate->toDateTimeString()}");

        $deletedFiles = 0;
        $deletedSize = 0;
        $errors = 0;

        // Get all files in uploads directory recursively
        $files = Storage::allFiles($uploadsPath);

        foreach ($files as $file) {
            // Skip .gitignore and other system files
            if (str_starts_with(basename($file), '.')) {
                continue;
            }

            try {
                $lastModified = Storage::lastModified($file);
                $fileDate = \Carbon\Carbon::createFromTimestamp($lastModified);

                if ($fileDate->lt($cutoffDate)) {
                    $fileSize = Storage::size($file);

                    if ($dryRun) {
                        $this->line("Would delete: {$file} (modified: {$fileDate->toDateTimeString()})");
                    } else {
                        Storage::delete($file);
                        $this->line("Deleted: {$file}");

                        Log::info('Cleanup: Upload deleted', [
                            'file' => $file,
                            'modified' => $fileDate->toDateTimeString(),
                            'size' => $fileSize,
                        ]);
                    }

                    $deletedFiles++;
                    $deletedSize += $fileSize;
                }
            } catch (\Exception $e) {
                $errors++;
                $this->error("Error processing {$file}: {$e->getMessage()}");
                Log::error('Cleanup: Upload deletion error', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Clean up empty directories
        if (! $dryRun) {
            $this->cleanupEmptyDirectories($uploadsPath);
        }

        // Summary
        $sizeFormatted = $this->formatBytes($deletedSize);
        $action = $dryRun ? 'Would delete' : 'Deleted';

        $this->newLine();
        $this->info('=== Upload Cleanup Summary ===');
        $this->info("{$action}: {$deletedFiles} file(s) ({$sizeFormatted})");

        if ($errors > 0) {
            $this->warn("Errors: {$errors}");
        }

        Log::info('Cleanup: Uploads completed', [
            'deleted_files' => $deletedFiles,
            'deleted_size' => $deletedSize,
            'errors' => $errors,
            'dry_run' => $dryRun,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Remove empty directories in the uploads folder.
     */
    protected function cleanupEmptyDirectories(string $basePath): void
    {
        $directories = Storage::directories($basePath);

        foreach ($directories as $dir) {
            // Recursively clean subdirectories first
            $this->cleanupEmptyDirectories($dir);

            // Check if directory is now empty
            $files = Storage::files($dir);
            $subdirs = Storage::directories($dir);

            if (empty($files) && empty($subdirs)) {
                Storage::deleteDirectory($dir);
                $this->line("Removed empty directory: {$dir}");
            }
        }
    }

    /**
     * Format bytes to human readable format.
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return round($bytes, 2).' '.$units[$index];
    }
}
