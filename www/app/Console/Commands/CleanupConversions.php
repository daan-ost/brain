<?php

namespace App\Console\Commands;

use App\Models\AnalyticsEvent;
use App\Models\Batch;
use App\Services\RetentionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupConversions extends Command
{
    protected $signature = 'cleanup:conversions {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Remove conversion results based on retention period (per-organization or default)';

    public function handle(RetentionService $retentionService): int
    {
        $this->info('Starting conversions cleanup...');

        $dryRun = $this->option('dry-run');
        $convertedPath = config('cleanup.paths.converted', 'converted');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No files will be deleted');
        }

        $deletedBatches = 0;
        $deletedSize = 0;
        $deletedBatchIds = [];
        $errors = 0;

        // Get all completed batches that haven't been cleaned yet
        $batches = Batch::whereNotNull('result_path')
            ->where('status', 'done')
            ->with('user.organizations')
            ->get();

        $this->info("Found {$batches->count()} batches to evaluate");

        foreach ($batches as $batch) {
            try {
                $cutoffDate = $retentionService->getCutoffDateForUser($batch->user);

                // Skip if batch hasn't reached retention period
                if ($batch->created_at->gte($cutoffDate)) {
                    continue;
                }

                // Skip if already processed by expires_at (handled by cleanup:expired-batches)
                if ($batch->expires_at && $batch->expires_at->isFuture()) {
                    continue;
                }

                $size = $this->cleanupBatch($batch, $dryRun);
                $deletedBatches++;
                $deletedSize += $size;
                $deletedBatchIds[] = $batch->id;

                $this->line(sprintf(
                    'Cleaned batch %s (user: %s, created: %s, retention: %d days)',
                    $batch->id,
                    $batch->user_id ?? 'guest',
                    $batch->created_at->toDateString(),
                    $retentionService->getRetentionDaysForUser($batch->user)
                ));
            } catch (\Exception $e) {
                $errors++;
                $this->error("Error cleaning batch {$batch->id}: {$e->getMessage()}");
                Log::error('Cleanup: Conversion deletion error', [
                    'batch_id' => $batch->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Clean up orphaned directories (no matching batch record)
        $this->info('Checking for orphaned conversion directories...');
        $orphanedCleanup = $this->cleanupOrphanedDirectories($convertedPath, $retentionService, $dryRun);
        $deletedSize += $orphanedCleanup['size'];

        // Summary
        $sizeFormatted = $this->formatBytes($deletedSize);
        $action = $dryRun ? 'Would clean' : 'Cleaned';

        $this->newLine();
        $this->info('=== Conversions Cleanup Summary ===');
        $this->info("{$action}: {$deletedBatches} batch(es), {$orphanedCleanup['count']} orphaned dir(s) ({$sizeFormatted})");

        if ($errors > 0) {
            $this->warn("Errors: {$errors}");
        }

        Log::info('Cleanup: Conversions completed', [
            'deleted_batches' => $deletedBatches,
            'orphaned_dirs' => $orphanedCleanup['count'],
            'deleted_size' => $deletedSize,
            'errors' => $errors,
            'dry_run' => $dryRun,
        ]);

        // Log analytics event (only if not dry-run and something was deleted)
        $totalDeleted = $deletedBatches + $orphanedCleanup['count'];
        if (! $dryRun && $totalDeleted > 0) {
            AnalyticsEvent::create([
                'user_id' => null,
                'guest_sid' => null,
                'event' => 'file_deleted_by_cleanup',
                'meta' => [
                    'cleanup_type' => 'conversions',
                    'deleted_count' => $deletedBatches,
                    'orphaned_dirs_count' => $orphanedCleanup['count'],
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
     */
    protected function cleanupBatch(Batch $batch, bool $dryRun): int
    {
        $totalSize = 0;
        $ownerIdentifier = $batch->getOwnerIdentifier();
        $batchDir = "converted/{$ownerIdentifier}/batch_{$batch->id}";

        // Delete the batch directory if it exists
        if (Storage::exists($batchDir)) {
            $files = Storage::allFiles($batchDir);
            foreach ($files as $file) {
                $totalSize += Storage::size($file);
            }

            if (! $dryRun) {
                Storage::deleteDirectory($batchDir);
            }
        }

        // Delete the result file if it exists outside batch directory
        if ($batch->result_path && Storage::exists($batch->result_path)) {
            if (! str_contains($batch->result_path, $batchDir)) {
                $totalSize += Storage::size($batch->result_path);
                if (! $dryRun) {
                    Storage::delete($batch->result_path);
                }
            }
        }

        // Clear the result_path in the database
        if (! $dryRun) {
            $batch->update([
                'result_path' => null,
                'result_size' => null,
            ]);

            Log::info('Cleanup: Conversion cleaned', [
                'batch_id' => $batch->id,
                'user_id' => $batch->user_id,
                'deleted_size' => $totalSize,
            ]);
        }

        return $totalSize;
    }

    /**
     * Clean up orphaned directories that don't have matching batch records.
     */
    protected function cleanupOrphanedDirectories(string $convertedPath, RetentionService $retentionService, bool $dryRun): array
    {
        $count = 0;
        $totalSize = 0;
        $defaultCutoff = now()->subDays($retentionService->getDefaultRetentionDays());

        // Get all owner directories
        $ownerDirs = Storage::directories($convertedPath);

        foreach ($ownerDirs as $ownerDir) {
            $batchDirs = Storage::directories($ownerDir);

            foreach ($batchDirs as $batchDir) {
                // Extract batch ID from directory name (batch_{uuid})
                $dirName = basename($batchDir);
                if (! str_starts_with($dirName, 'batch_')) {
                    continue;
                }

                $batchId = substr($dirName, 6); // Remove 'batch_' prefix

                // Check if batch exists
                $batch = Batch::find($batchId);

                if (! $batch) {
                    // Orphaned directory - check age
                    $files = Storage::allFiles($batchDir);
                    if (empty($files)) {
                        continue;
                    }

                    $oldestFile = null;
                    $dirSize = 0;

                    foreach ($files as $file) {
                        $lastModified = Storage::lastModified($file);
                        if (! $oldestFile || $lastModified < $oldestFile) {
                            $oldestFile = $lastModified;
                        }
                        $dirSize += Storage::size($file);
                    }

                    $dirDate = \Carbon\Carbon::createFromTimestamp($oldestFile);

                    if ($dirDate->lt($defaultCutoff)) {
                        if ($dryRun) {
                            $this->line("Would delete orphaned: {$batchDir} (modified: {$dirDate->toDateTimeString()})");
                        } else {
                            Storage::deleteDirectory($batchDir);
                            $this->line("Deleted orphaned: {$batchDir}");

                            Log::info('Cleanup: Orphaned conversion directory deleted', [
                                'directory' => $batchDir,
                                'size' => $dirSize,
                            ]);
                        }

                        $count++;
                        $totalSize += $dirSize;
                    }
                }
            }

            // Clean up empty owner directories
            if (! $dryRun) {
                $remainingDirs = Storage::directories($ownerDir);
                $remainingFiles = Storage::files($ownerDir);

                if (empty($remainingDirs) && empty($remainingFiles)) {
                    Storage::deleteDirectory($ownerDir);
                    $this->line("Removed empty owner directory: {$ownerDir}");
                }
            }
        }

        return ['count' => $count, 'size' => $totalSize];
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
