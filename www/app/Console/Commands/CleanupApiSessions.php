<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanupApiSessions extends Command
{
    protected $signature = 'cleanup:api-sessions {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Remove expired API v1 sessions';

    public function handle(): int
    {
        $this->info('Starting API sessions cleanup...');

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No records will be deleted');
        }

        // Count expired sessions
        $expiredCount = DB::table('api_v1_sessions')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->count();

        // Count sessions that have been inactive beyond their expiration_time_seconds
        // Use PHP-based calculation for database compatibility
        $inactiveSessions = DB::table('api_v1_sessions')
            ->whereNull('expires_at')
            ->orWhere('expires_at', '>=', now())
            ->get(['id', 'expiration_time_seconds', 'last_activity_at']);

        $inactiveIds = $inactiveSessions->filter(function ($session) {
            $expiresAt = \Carbon\Carbon::parse($session->last_activity_at)
                ->addSeconds($session->expiration_time_seconds);

            return $expiresAt->isPast();
        })->pluck('id')->toArray();

        $inactiveCount = count($inactiveIds);
        $totalExpired = $expiredCount + $inactiveCount;

        $this->info("Found {$expiredCount} sessions with expired expires_at");
        $this->info("Found {$inactiveCount} inactive sessions beyond expiration time");

        if ($totalExpired === 0) {
            $this->info('No expired sessions to clean');

            return Command::SUCCESS;
        }

        if (! $dryRun) {
            // Delete sessions with explicit expires_at
            $deletedByExpiry = DB::table('api_v1_sessions')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now())
                ->delete();

            // Delete inactive sessions by ID
            $deletedByInactivity = 0;
            if (! empty($inactiveIds)) {
                $deletedByInactivity = DB::table('api_v1_sessions')
                    ->whereIn('id', $inactiveIds)
                    ->delete();
            }

            $totalDeleted = $deletedByExpiry + $deletedByInactivity;

            $this->info("Deleted {$deletedByExpiry} sessions by expiry date");
            $this->info("Deleted {$deletedByInactivity} sessions by inactivity");

            Log::info('Cleanup: API sessions completed', [
                'deleted_by_expiry' => $deletedByExpiry,
                'deleted_by_inactivity' => $deletedByInactivity,
                'total_deleted' => $totalDeleted,
            ]);
        } else {
            $this->line("Would delete {$totalExpired} session(s)");
        }

        $this->newLine();
        $this->info('=== API Sessions Cleanup Summary ===');
        $action = $dryRun ? 'Would delete' : 'Deleted';
        $this->info("{$action}: {$totalExpired} expired session(s)");

        return Command::SUCCESS;
    }
}
