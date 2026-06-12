<?php

namespace App\Console\Commands;

use App\Models\OrganizationSenderLog;
use Illuminate\Console\Command;

class CleanupSenderLogs extends Command
{
    protected $signature = 'sender:cleanup-logs {--days=30 : Number of days to keep logs}';

    protected $description = 'Remove old sender email logs';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));

        $cutoff = now()->subDays($days);
        $deleted = 0;

        do {
            $chunk = OrganizationSenderLog::where('created_at', '<', $cutoff)->limit(1000)->delete();
            $deleted += $chunk;
        } while ($chunk > 0);

        $this->info("Deleted {$deleted} sender logs older than {$days} days.");

        return self::SUCCESS;
    }
}
