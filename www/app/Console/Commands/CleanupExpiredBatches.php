<?php

namespace App\Console\Commands;

use App\Models\AnalyticsEvent;
use App\Models\Batch;
use App\Models\FileKey;
use App\Services\AuditService;
use App\Services\RetentionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupExpiredBatches extends Command
{
    protected $signature = 'cleanup:expired-batches {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Remove expired batches and their associated files';

    public function handle(RetentionService $retentionService, AuditService $auditService): int
    {
        $this->info('Starting expired batches cleanup...');

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No files will be deleted');
        }

        $deletedBatches = 0;
        $deletedSize = 0;
        $deletedBatchIds = [];
        $deletedFileKeys = 0;
        $errors = 0;

        // Find batches that have expired based on expires_at
        $expiredBatches = Batch::whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->whereNotNull('result_path')
            ->get();

        $this->info("Found {$expiredBatches->count()} expired batches to process");

        foreach ($expiredBatches as $batch) {
            try {
                $result = $this->cleanupBatch($batch, $dryRun, $auditService);
                $deletedBatches++;
                $deletedSize += $result['size'];
                $deletedFileKeys += $result['file_keys'];
                $deletedBatchIds[] = $batch->id;
            } catch (\Exception $e) {
                $errors++;
                $this->error("Error cleaning batch {$batch->id}: {$e->getMessage()}");
                Log::error('Cleanup: Batch deletion error', [
                    'batch_id' => $batch->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Also find batches based on retention period (no expires_at set)
        $this->info('Checking batches without explicit expiry based on retention period...');

        $batchesWithoutExpiry = Batch::whereNull('expires_at')
            ->whereNotNull('result_path')
            ->where('status', 'done')
            ->with('user.organizations')
            ->get();

        foreach ($batchesWithoutExpiry as $batch) {
            try {
                $cutoffDate = $retentionService->getCutoffDateForUser($batch->user);

                if ($batch->created_at->lt($cutoffDate)) {
                    $result = $this->cleanupBatch($batch, $dryRun, $auditService);
                    $deletedBatches++;
                    $deletedSize += $result['size'];
                    $deletedFileKeys += $result['file_keys'];
                    $deletedBatchIds[] = $batch->id;
                }
            } catch (\Exception $e) {
                $errors++;
                $this->error("Error cleaning batch {$batch->id}: {$e->getMessage()}");
                Log::error('Cleanup: Batch deletion error', [
                    'batch_id' => $batch->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Summary
        $sizeFormatted = $this->formatBytes($deletedSize);
        $action = $dryRun ? 'Would clean' : 'Cleaned';

        $this->newLine();
        $this->info('=== Expired Batches Cleanup Summary ===');
        $this->info("{$action}: {$deletedBatches} batch(es) ({$sizeFormatted})");
        $this->info("{$action}: {$deletedFileKeys} file key(s)");

        if ($errors > 0) {
            $this->warn("Errors: {$errors}");
        }

        Log::info('Cleanup: Expired batches completed', [
            'deleted_batches' => $deletedBatches,
            'deleted_file_keys' => $deletedFileKeys,
            'deleted_size' => $deletedSize,
            'errors' => $errors,
            'dry_run' => $dryRun,
        ]);

        // Log analytics event (only if not dry-run and something was deleted)
        if (! $dryRun && $deletedBatches > 0) {
            AnalyticsEvent::create([
                'user_id' => null,
                'guest_sid' => null,
                'event' => 'file_deleted_by_cleanup',
                'meta' => [
                    'cleanup_type' => 'expired_batches',
                    'deleted_count' => $deletedBatches,
                    'deleted_file_keys' => $deletedFileKeys,
                    'deleted_size_bytes' => $deletedSize,
                    'deleted_size_formatted' => $sizeFormatted,
                    'batch_ids' => array_slice($deletedBatchIds, 0, 100), // Limit to 100 IDs
                    'errors' => $errors,
                ],
            ]);
        }

        return Command::SUCCESS;
    }

    /**
     * Clean up a single batch and its associated files.
     *
     * @return array{size: int, file_keys: int}
     */
    protected function cleanupBatch(Batch $batch, bool $dryRun, AuditService $auditService): array
    {
        $totalSize = 0;
        $deletedFileKeys = 0;
        $ownerIdentifier = $batch->getOwnerIdentifier();
        $batchDir = "converted/{$ownerIdentifier}/batch_{$batch->id}";

        // Delete the batch directory if it exists
        if (Storage::exists($batchDir)) {
            $files = Storage::allFiles($batchDir);
            foreach ($files as $file) {
                $totalSize += Storage::size($file);
            }

            if ($dryRun) {
                $this->line("Would delete directory: {$batchDir} ({$this->formatBytes($totalSize)})");
            } else {
                Storage::deleteDirectory($batchDir);
                $this->line("Deleted directory: {$batchDir}");
            }
        }

        // Delete the result file if it exists and is outside the batch directory
        if ($batch->result_path && Storage::exists($batch->result_path)) {
            if (! str_contains($batch->result_path, $batchDir)) {
                $fileSize = Storage::size($batch->result_path);
                $totalSize += $fileSize;

                if ($dryRun) {
                    $this->line("Would delete file: {$batch->result_path}");
                } else {
                    Storage::delete($batch->result_path);
                    $this->line("Deleted file: {$batch->result_path}");
                }
            }
        }

        // Clean up associated file_keys
        $fileKeys = FileKey::where('batch_id', $batch->id)->get();
        foreach ($fileKeys as $fileKey) {
            if ($dryRun) {
                $this->line("Would delete file_key: {$fileKey->id} ({$fileKey->file_path})");
            } else {
                // Log audit event before deletion
                $auditService->logFileDeleted($fileKey, [
                    'reason' => 'batch_expired',
                    'batch_expires_at' => $batch->expires_at?->toDateTimeString(),
                ]);

                // Delete the encrypted file if it exists
                if ($fileKey->file_path && Storage::exists($fileKey->file_path)) {
                    Storage::delete($fileKey->file_path);
                }

                // Delete the file_key record
                $fileKey->delete();
                $this->line("Deleted file_key: {$fileKey->id}");
            }
            $deletedFileKeys++;
        }

        // Also clean up file_keys by workflow_execution_id if batch has one
        if ($batch->workflow_execution_id) {
            $execFileKeys = FileKey::where('workflow_execution_id', $batch->workflow_execution_id)
                ->whereNull('batch_id')
                ->get();

            foreach ($execFileKeys as $fileKey) {
                if ($dryRun) {
                    $this->line("Would delete file_key (by execution): {$fileKey->id}");
                } else {
                    $auditService->logFileDeleted($fileKey, [
                        'reason' => 'workflow_execution_expired',
                        'workflow_execution_id' => $batch->workflow_execution_id,
                    ]);

                    if ($fileKey->file_path && Storage::exists($fileKey->file_path)) {
                        Storage::delete($fileKey->file_path);
                    }

                    $fileKey->delete();
                    $this->line("Deleted file_key (by execution): {$fileKey->id}");
                }
                $deletedFileKeys++;
            }
        }

        // Clear the result_path in the database (keep the record)
        if (! $dryRun) {
            $batch->update([
                'result_path' => null,
                'result_size' => null,
                'is_encrypted' => false,
            ]);

            Log::info('Cleanup: Batch cleaned', [
                'batch_id' => $batch->id,
                'user_id' => $batch->user_id,
                'deleted_size' => $totalSize,
                'deleted_file_keys' => $deletedFileKeys,
            ]);
        }

        return [
            'size' => $totalSize,
            'file_keys' => $deletedFileKeys,
        ];
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
