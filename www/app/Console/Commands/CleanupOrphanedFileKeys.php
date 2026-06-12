<?php

namespace App\Console\Commands;

use App\Models\AnalyticsEvent;
use App\Models\FileKey;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupOrphanedFileKeys extends Command
{
    protected $signature = 'cleanup:orphaned-file-keys {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Remove orphaned file_keys where the physical encrypted file no longer exists';

    public function handle(AuditService $auditService): int
    {
        $this->info('Starting orphaned file_keys cleanup...');

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No records will be deleted');
        }

        $deletedCount = 0;
        $checkedCount = 0;
        $errors = 0;

        // Get all file_keys
        $fileKeys = FileKey::all();
        $totalCount = $fileKeys->count();

        $this->info("Checking {$totalCount} file_key records...");

        $progressBar = $this->output->createProgressBar($totalCount);
        $progressBar->start();

        foreach ($fileKeys as $fileKey) {
            $checkedCount++;

            try {
                // Check if the encrypted file exists
                if ($fileKey->file_path && ! Storage::exists($fileKey->file_path)) {
                    if ($dryRun) {
                        $this->newLine();
                        $this->line("Would delete orphaned file_key: {$fileKey->id} (missing: {$fileKey->file_path})");
                    } else {
                        // Log audit event
                        $auditService->logFileDeleted($fileKey, [
                            'reason' => 'orphaned_file_key',
                            'missing_path' => $fileKey->file_path,
                        ]);

                        // Delete the record
                        $fileKey->delete();
                    }
                    $deletedCount++;
                }
            } catch (\Exception $e) {
                $errors++;
                $this->newLine();
                $this->error("Error checking file_key {$fileKey->id}: {$e->getMessage()}");
                Log::error('Cleanup: Orphaned file_key check error', [
                    'file_key_id' => $fileKey->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Summary
        $action = $dryRun ? 'Would delete' : 'Deleted';

        $this->info('=== Orphaned File Keys Cleanup Summary ===');
        $this->info("Checked: {$checkedCount} file_key(s)");
        $this->info("{$action}: {$deletedCount} orphaned file_key(s)");

        if ($errors > 0) {
            $this->warn("Errors: {$errors}");
        }

        Log::info('Cleanup: Orphaned file_keys completed', [
            'checked' => $checkedCount,
            'deleted' => $deletedCount,
            'errors' => $errors,
            'dry_run' => $dryRun,
        ]);

        // Log analytics event (only if not dry-run and something was deleted)
        if (! $dryRun && $deletedCount > 0) {
            AnalyticsEvent::create([
                'user_id' => null,
                'guest_sid' => null,
                'event' => 'file_deleted_by_cleanup',
                'meta' => [
                    'cleanup_type' => 'orphaned_file_keys',
                    'checked_count' => $checkedCount,
                    'deleted_count' => $deletedCount,
                    'errors' => $errors,
                ],
            ]);
        }

        return Command::SUCCESS;
    }
}
