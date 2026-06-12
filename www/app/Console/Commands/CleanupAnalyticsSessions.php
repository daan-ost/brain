<?php

namespace App\Console\Commands;

use App\Models\AnalyticsSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupAnalyticsSessions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'analytics:cleanup-sessions
                            {--days= : Override default retention days}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove analytics sessions older than configured days';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = $this->option('days') ?? config('analytics.cleanup.sessions_older_than_days', 7);
        $dryRun = $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $this->info("Cleaning up analytics sessions older than {$days} days...");

        if ($dryRun) {
            $this->warn('DRY RUN - no data will be deleted');
        }

        // Count sessions to be deleted
        $count = AnalyticsSession::where('started_at', '<', $cutoff)->count();

        if ($count === 0) {
            $this->info('No sessions to clean up');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->info("Would delete {$count} sessions");

            return Command::SUCCESS;
        }

        // Delete in chunks to avoid memory issues
        $deleted = 0;
        AnalyticsSession::where('started_at', '<', $cutoff)
            ->chunkById(1000, function ($sessions) use (&$deleted) {
                $ids = $sessions->pluck('id')->toArray();
                AnalyticsSession::whereIn('id', $ids)->delete();
                $deleted += count($ids);
            });

        $this->info("Deleted {$deleted} sessions older than {$days} days");

        Log::info('Analytics sessions cleanup completed', [
            'deleted_count' => $deleted,
            'retention_days' => $days,
            'cutoff_date' => $cutoff->toDateTimeString(),
            'command' => 'analytics:cleanup-sessions',
        ]);

        return Command::SUCCESS;
    }
}
