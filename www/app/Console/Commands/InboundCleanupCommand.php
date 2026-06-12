<?php

namespace App\Console\Commands;

use App\Models\InboundEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class InboundCleanupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inbound:cleanup {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up expired inbound email files';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN - No files will be deleted');
        }

        // Find all inbound emails that are due for cleanup
        $expiredEmails = InboundEmail::whereNotNull('cleanup_scheduled_at')
            ->where('cleanup_scheduled_at', '<=', now())
            ->whereNotNull('output_file_path')
            ->get();

        if ($expiredEmails->isEmpty()) {
            $this->info('No expired inbound emails to clean up.');

            return Command::SUCCESS;
        }

        $this->info("Found {$expiredEmails->count()} expired inbound email(s) to clean up.");

        $deletedCount = 0;
        $errorCount = 0;

        foreach ($expiredEmails as $email) {
            $this->line("Processing InboundEmail #{$email->id} (user: {$email->user_id})");

            try {
                // Delete the entire inbound email directory
                // Validate email ID is numeric to prevent path traversal
                if (! is_numeric($email->id)) {
                    throw new \RuntimeException("Invalid email ID: {$email->id}");
                }

                $directory = "inbound-attachments/{$email->id}";

                if (Storage::disk('local')->exists($directory)) {
                    if ($dryRun) {
                        $this->line("  Would delete directory: {$directory}");
                    } else {
                        Storage::disk('local')->deleteDirectory($directory);
                        $this->line("  Deleted directory: {$directory}");
                    }
                }

                // Also check for output files - validate path is within expected bounds
                if ($email->output_file_path) {
                    // Ensure path doesn't contain traversal sequences and is within allowed directories
                    $normalizedPath = $email->output_file_path;
                    if (
                        str_contains($normalizedPath, '..') ||
                        str_contains($normalizedPath, "\0") ||
                        ! preg_match('#^inbound-(attachments|emails|output|results)/#', $normalizedPath)
                    ) {
                        Log::warning('Suspicious output_file_path detected, skipping', [
                            'inbound_email_id' => $email->id,
                            'path' => $normalizedPath,
                        ]);
                    } elseif (Storage::disk('local')->exists($normalizedPath)) {
                        if ($dryRun) {
                            $this->line("  Would delete output file: {$normalizedPath}");
                        } else {
                            Storage::disk('local')->delete($normalizedPath);
                            $this->line("  Deleted output file: {$normalizedPath}");
                        }
                    }
                }

                // Delete attachment records and clear the output path
                if (! $dryRun) {
                    // Delete associated attachment records
                    $attachmentCount = $email->attachments()->count();
                    $email->attachments()->delete();

                    if ($attachmentCount > 0) {
                        $this->line("  Deleted {$attachmentCount} attachment record(s)");
                    }

                    $email->update([
                        'output_file_path' => null,
                    ]);
                }

                $deletedCount++;
            } catch (\Exception $e) {
                $errorCount++;
                $this->error("  Error cleaning up InboundEmail #{$email->id}: {$e->getMessage()}");

                Log::error('Inbound cleanup failed', [
                    'inbound_email_id' => $email->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->info("Cleanup complete: {$deletedCount} cleaned, {$errorCount} errors.");

        Log::info('Inbound cleanup completed', [
            'deleted' => $deletedCount,
            'errors' => $errorCount,
            'dry_run' => $dryRun,
        ]);

        return $errorCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
