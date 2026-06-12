<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ScheduleHealthCheck extends Command
{
    protected $signature = 'schedule:health-check';

    protected $description = 'Check health of critical scheduled tasks by verifying last run timestamps';

    /**
     * Critical tasks and their maximum allowed staleness.
     */
    private function monitoredTasks(): array
    {
        return [
            // Business logic tasks
            'license-process-credits' => ['interval' => 'hourly', 'stale_after_minutes' => 120],
            'license-send-notifications' => ['interval' => 'daily 09:00', 'stale_after_minutes' => 1560],
            'license-process-invoice-renewals' => ['interval' => 'daily 08:00', 'stale_after_minutes' => 1560],
            'messages-send-unread-notifications' => ['interval' => 'every 5 min', 'stale_after_minutes' => 15],
            'license-process-price-changes' => ['interval' => 'daily 09:00', 'stale_after_minutes' => 1560],

            // Important cleanup tasks
            'cleanup-uploads' => ['interval' => 'every 5 min', 'stale_after_minutes' => 15],
            'cleanup-expired-batches' => ['interval' => 'daily 03:00', 'stale_after_minutes' => 1560],
        ];
    }

    public function handle(): int
    {
        $hasStale = false;
        $rows = [];

        foreach ($this->monitoredTasks() as $name => $config) {
            $cacheKey = "schedule:last_run:{$name}";
            $lastRun = Cache::get($cacheKey);

            if ($lastRun === null) {
                $rows[] = [$name, $config['interval'], 'NEVER', '<fg=yellow>UNKNOWN</>'];
                continue;
            }

            $minutesAgo = (int) $lastRun->diffInMinutes(now());
            $isStale = $minutesAgo > $config['stale_after_minutes'];

            if ($isStale) {
                $hasStale = true;
            }

            $rows[] = [
                $name,
                $config['interval'],
                $lastRun->format('Y-m-d H:i:s')." ({$minutesAgo}m ago)",
                $isStale ? '<fg=red>STALE</>' : '<fg=green>OK</>',
            ];
        }

        $this->table(['Task', 'Interval', 'Last Run', 'Status'], $rows);

        if ($hasStale) {
            $this->error('One or more scheduled tasks are stale!');

            return self::FAILURE;
        }

        $this->info('All monitored scheduled tasks are healthy.');

        return self::SUCCESS;
    }
}
