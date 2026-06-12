<?php

namespace App\Console\Commands;

use App\Models\Batch;
use App\Services\RetentionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupFailedConversions extends Command
{
    protected $signature = 'cleanup:failed-conversions {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Remove failed conversions older than 7 days';

    public function handle(RetentionService $retentionService): int
    {
        $this->info('Starting failed conversions cleanup...');

        $cutoffDate = $retentionService->getFailedConversionCutoffDate();
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No files will be deleted');
        }

        $this->info("Cutoff date: {$cutoffDate->toDateTimeString()}");

        $deletedBatches = 0;
        $deletedSize = 0;
        $errors = 0;

        // Find failed batches older than cutoff
        $failedBatches = Batch::where('status', 'error')
            ->where('created_at', '<', $cutoffDate)
            ->get();

        $this->info("Found {$failedBatches->count()} failed batches to clean");

        foreach ($failedBatches as $batch) {
            try {
                $size = $this->cleanupFailedBatch($batch, $dryRun);
                $deletedBatches++;
                $deletedSize += $size;
            } catch (\Exception $e) {
                $errors++;
                $this->error("Error cleaning batch {$batch->id}: {$e->getMessage()}");
                Log::error('Cleanup: Failed conversion deletion error', [
                    'batch_id' => $batch->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Summary
        $sizeFormatted = $this->formatBytes($deletedSize);
        $action = $dryRun ? 'Would clean' : 'Cleaned';

        $this->newLine();
        $this->info('=== Failed Conversions Cleanup Summary ===');
        $this->info("{$action}: {$deletedBatches} failed batch(es) ({$sizeFormatted})");

        if ($errors > 0) {
            $this->warn("Errors: {$errors}");
        }

        Log::info('Cleanup: Failed conversions completed', [
            'deleted_batches' => $deletedBatches,
            'deleted_size' => $deletedSize,
            'errors' => $errors,
            'dry_run' => $dryRun,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Clean up a failed batch and its associated files.
     */
    protected function cleanupFailedBatch(Batch $batch, bool $dryRun): int
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

            if ($dryRun) {
                $this->line("Would delete: {$batchDir}");
            } else {
                Storage::deleteDirectory($batchDir);
                $this->line("Deleted: {$batchDir}");
            }
        }

        // Delete result file if exists
        if ($batch->result_path && Storage::exists($batch->result_path)) {
            $totalSize += Storage::size($batch->result_path);
            if (! $dryRun) {
                Storage::delete($batch->result_path);
            }
        }

        // Clear file references but keep error info for debugging
        if (! $dryRun) {
            $batch->update([
                'result_path' => null,
                'result_size' => null,
            ]);

            Log::info('Cleanup: Failed conversion cleaned', [
                'batch_id' => $batch->id,
                'user_id' => $batch->user_id,
                'error_json' => $batch->error_json,
                'deleted_size' => $totalSize,
            ]);
        }

        return $totalSize;
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
