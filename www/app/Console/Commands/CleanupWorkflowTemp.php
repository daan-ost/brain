<?php

namespace App\Console\Commands;

use App\Models\WorkflowExecution;
use App\Services\RetentionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class CleanupWorkflowTemp extends Command
{
    protected $signature = 'cleanup:workflow-temp {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Remove orphaned workflow temp files (completed workflows + fallback 24h)';

    public function handle(RetentionService $retentionService): int
    {
        $this->info('Starting workflow temp cleanup...');

        $cutoffDate = $retentionService->getWorkflowTempCutoffDate();
        $workflowResultsPath = storage_path('app/workflow_results');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No files will be deleted');
        }

        $this->info("Cutoff date for orphaned files: {$cutoffDate->toDateTimeString()}");

        if (! File::exists($workflowResultsPath)) {
            $this->info('No workflow_results directory found. Nothing to clean.');

            return Command::SUCCESS;
        }

        $deletedDirs = 0;
        $deletedSize = 0;
        $errors = 0;

        // Get all execution directories
        $executionDirs = File::directories($workflowResultsPath);

        foreach ($executionDirs as $dir) {
            $executionId = basename($dir);

            try {
                // Check if this execution exists and is completed
                $execution = WorkflowExecution::find($executionId);

                $shouldDelete = false;

                if ($execution) {
                    // Delete if execution is done or has error
                    if (in_array($execution->status, ['done', 'error'])) {
                        $shouldDelete = true;
                        $reason = "execution status: {$execution->status}";
                    }
                } else {
                    // No execution record - check file age (orphaned)
                    $dirModified = File::lastModified($dir);
                    $dirDate = \Carbon\Carbon::createFromTimestamp($dirModified);

                    if ($dirDate->lt($cutoffDate)) {
                        $shouldDelete = true;
                        $reason = "orphaned (modified: {$dirDate->toDateTimeString()})";
                    }
                }

                if ($shouldDelete) {
                    $dirSize = $this->getDirectorySize($dir);

                    if ($dryRun) {
                        $this->line("Would delete: {$dir} ({$reason})");
                    } else {
                        File::deleteDirectory($dir);
                        $this->line("Deleted: {$dir} ({$reason})");

                        Log::info('Cleanup: Workflow temp deleted', [
                            'execution_id' => $executionId,
                            'reason' => $reason,
                            'size' => $dirSize,
                        ]);
                    }

                    $deletedDirs++;
                    $deletedSize += $dirSize;
                }
            } catch (\Exception $e) {
                $errors++;
                $this->error("Error processing {$dir}: {$e->getMessage()}");
                Log::error('Cleanup: Workflow temp deletion error', [
                    'directory' => $dir,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Summary
        $sizeFormatted = $this->formatBytes($deletedSize);
        $action = $dryRun ? 'Would delete' : 'Deleted';

        $this->newLine();
        $this->info('=== Workflow Temp Cleanup Summary ===');
        $this->info("{$action}: {$deletedDirs} directory(ies) ({$sizeFormatted})");

        if ($errors > 0) {
            $this->warn("Errors: {$errors}");
        }

        Log::info('Cleanup: Workflow temp completed', [
            'deleted_dirs' => $deletedDirs,
            'deleted_size' => $deletedSize,
            'errors' => $errors,
            'dry_run' => $dryRun,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Get the total size of a directory.
     */
    protected function getDirectorySize(string $path): int
    {
        $size = 0;
        foreach (File::allFiles($path) as $file) {
            $size += $file->getSize();
        }

        return $size;
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
