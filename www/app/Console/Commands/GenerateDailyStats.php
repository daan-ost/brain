<?php

namespace App\Console\Commands;

use App\Services\DailyStatsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateDailyStats extends Command
{
    protected $signature = 'stats:generate
        {--date= : Single date to generate (Y-m-d). Defaults to yesterday.}
        {--from= : Start of date range (Y-m-d).}
        {--to=   : End of date range (Y-m-d). Defaults to today.}
        {--backfill : Generate from the first paid order up to today (skips existing rows).}
        {--force : Overwrite existing rows.}';

    protected $description = 'Generate daily_stats rollup rows from source tables.';

    public function handle(DailyStatsService $service): int
    {
        [$from, $to] = $this->resolveDateRange();

        $this->info("Generating stats from {$from->format('Y-m-d')} to {$to->format('Y-m-d')}…");

        $bar = $this->output->createProgressBar((int) $from->diffInDays($to) + 1);
        $bar->start();

        $force = (bool) $this->option('force');
        $count = 0;
        $current = $from->copy();

        while ($current->lte($to)) {
            $dateStr = $current->format('Y-m-d');
            $isToday = $current->isToday();

            if (! $force && ! $isToday) {
                if (\App\Models\DailyStat::where('date', $dateStr)->exists()) {
                    $bar->advance();
                    $current->addDay();
                    continue;
                }
            }

            $data = $service->computeForDate($current->copy());
            \App\Models\DailyStat::updateOrCreate(['date' => $dateStr], $data);
            $count++;

            $bar->advance();
            $current->addDay();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done. {$count} date(s) written.");

        return self::SUCCESS;
    }

    /** @return array{Carbon, Carbon} */
    private function resolveDateRange(): array
    {
        if ($this->option('backfill')) {
            $first = \App\Models\Order::where('status', 'paid')
                ->whereNotNull('paid_at')
                ->orderBy('paid_at')
                ->value('paid_at');

            $from = $first ? Carbon::parse($first)->startOfDay() : Carbon::today()->subDays(30);

            return [$from, Carbon::today()];
        }

        if ($this->option('from')) {
            $from = Carbon::parse($this->option('from'));
            $to = $this->option('to') ? Carbon::parse($this->option('to')) : Carbon::today();

            return [$from, $to];
        }

        if ($this->option('date')) {
            $date = Carbon::parse($this->option('date'));

            return [$date, $date];
        }

        // Default: yesterday
        return [Carbon::yesterday(), Carbon::yesterday()];
    }
}
