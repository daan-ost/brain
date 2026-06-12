<?php

namespace App\Console\Commands;

use App\Models\Invitation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupExpiredInvitations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cleanup:expired-invitations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark expired pending invitations and delete old accepted/rejected invitations';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting expired invitations cleanup...');

        // Step 1: Mark expired pending invitations
        $expiredPending = Invitation::where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->get();

        $markedExpired = 0;
        foreach ($expiredPending as $invitation) {
            $invitation->markAsExpired();
            $markedExpired++;
        }

        if ($markedExpired > 0) {
            $this->info("Marked {$markedExpired} pending invitations as expired");
            Log::info('Expired invitations marked', [
                'count' => $markedExpired,
                'command' => 'cleanup:expired-invitations',
            ]);
        }

        // Step 2: Delete old accepted/rejected/expired/revoked invitations (> 90 days)
        $cutoffDate = now()->subDays(90);
        $deletedCount = Invitation::whereIn('status', ['accepted', 'rejected', 'expired', 'revoked'])
            ->where('updated_at', '<', $cutoffDate)
            ->delete();

        if ($deletedCount > 0) {
            $this->info("Deleted {$deletedCount} old invitations (> 90 days)");
            Log::info('Old invitations deleted', [
                'count' => $deletedCount,
                'cutoff_date' => $cutoffDate->toDateTimeString(),
                'command' => 'cleanup:expired-invitations',
            ]);
        }

        // Summary
        $totalCleaned = $markedExpired + $deletedCount;
        if ($totalCleaned === 0) {
            $this->info('No invitations needed cleanup');
        } else {
            $this->info("✅ Cleanup complete: {$markedExpired} marked expired, {$deletedCount} deleted");
        }

        return Command::SUCCESS;
    }
}
