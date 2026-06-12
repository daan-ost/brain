<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CleanupExpiredEmailChanges extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:cleanup-expired-changes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup expired email change requests';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Cleaning up expired email change requests...');

        $count = User::whereNotNull('email_change_token')
            ->where('email_change_token_expires_at', '<', now())
            ->update([
                'pending_email' => null,
                'email_change_token' => null,
                'email_change_requested_at' => null,
                'email_change_token_expires_at' => null,
            ]);

        $this->info("Cleaned up {$count} expired email change request(s).");

        return Command::SUCCESS;
    }
}
