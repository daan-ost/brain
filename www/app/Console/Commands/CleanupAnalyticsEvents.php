<?php

namespace App\Console\Commands;

use App\Models\AnalyticsEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CleanupAnalyticsEvents extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'analytics:cleanup-events
                            {--days= : Override default retention days}
                            {--archive : Archive events to analytics_events_archive before deleting}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove or archive analytics events older than configured days';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = $this->option('days') ?? config('analytics.cleanup.events_older_than_days', 30);
        $archive = $this->option('archive');
        $dryRun = $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $this->info("Cleaning up analytics events older than {$days} days...");

        if ($dryRun) {
            $this->warn('DRY RUN - no data will be deleted');
        }

        // Count events to be processed
        $count = AnalyticsEvent::where('created_at', '<', $cutoff)->count();

        if ($count === 0) {
            $this->info('No events to clean up');

            return Command::SUCCESS;
        }

        $this->info("Found {$count} events to process");

        if ($dryRun) {
            $action = $archive ? 'archive and delete' : 'delete';
            $this->info("Would {$action} {$count} events");

            return Command::SUCCESS;
        }

        // Archive if requested
        if ($archive) {
            $archived = $this->archiveEvents($cutoff);
            if ($archived === false) {
                return Command::FAILURE;
            }
            $this->info("Archived {$archived} events");
        }

        // Delete events
        $deleted = 0;
        AnalyticsEvent::where('created_at', '<', $cutoff)
            ->chunkById(1000, function ($events) use (&$deleted) {
                $ids = $events->pluck('id')->toArray();
                AnalyticsEvent::whereIn('id', $ids)->delete();
                $deleted += count($ids);
            });

        $this->info("Deleted {$deleted} events older than {$days} days");

        Log::info('Analytics events cleanup completed', [
            'deleted_count' => $deleted,
            'archived' => $archive,
            'retention_days' => $days,
            'cutoff_date' => $cutoff->toDateTimeString(),
            'command' => 'analytics:cleanup-events',
        ]);

        return Command::SUCCESS;
    }

    /**
     * Archive events to analytics_events_archive table
     */
    private function archiveEvents($cutoff): int|false
    {
        // Check if archive table exists
        if (! Schema::hasTable('analytics_events_archive')) {
            $this->error('Archive table analytics_events_archive does not exist');
            $this->info('Create it with: php artisan make:migration create_analytics_events_archive_table');

            return false;
        }

        try {
            $archived = DB::affectingStatement('
                INSERT INTO analytics_events_archive
                SELECT * FROM analytics_events WHERE created_at < ?
            ', [$cutoff]);

            return $archived;
        } catch (\Exception $e) {
            $this->error('Failed to archive events: '.$e->getMessage());
            Log::error('Analytics events archive failed', [
                'error' => $e->getMessage(),
                'cutoff' => $cutoff->toDateTimeString(),
            ]);

            return false;
        }
    }
}
