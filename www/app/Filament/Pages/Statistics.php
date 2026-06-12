<?php

namespace App\Filament\Pages;

use App\Models\DailyStat;
use App\Services\DailyStatsService;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

class Statistics extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Analytics';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.statistics';

    protected static ?string $title = 'Statistieken';

    // Period preset: 7d | 30d | 90d | mtd | prev_month | ytd | 12m | custom
    #[Url(as: 'p')]
    public string $period = '30d';

    // Compare mode: none | previous | year
    #[Url(as: 'c')]
    public string $compareMode = 'none';

    // Custom date range
    #[Url(as: 'f')]
    public ?string $customFrom = null;

    #[Url(as: 't')]
    public ?string $customTo = null;

    // ----------------------------------------------------------------
    // Computed: resolved date ranges
    // ----------------------------------------------------------------

    #[Computed]
    public function periodRange(): array
    {
        return DailyStatsService::resolvePeriod($this->period, $this->customFrom, $this->customTo);
    }

    #[Computed]
    public function compareRange(): ?array
    {
        return DailyStatsService::resolveComparePeriod(
            $this->compareMode,
            $this->periodRange['from'],
            $this->periodRange['to'],
        );
    }

    // ----------------------------------------------------------------
    // Computed: aggregated KPI stats
    // ----------------------------------------------------------------

    #[Computed]
    public function stats(): array
    {
        return app(DailyStatsService::class)->aggregate(
            $this->periodRange['from'],
            $this->periodRange['to'],
        );
    }

    #[Computed]
    public function compareStats(): ?array
    {
        if ($this->compareRange === null) {
            return null;
        }

        return app(DailyStatsService::class)->aggregate(
            $this->compareRange['from'],
            $this->compareRange['to'],
        );
    }

    // ----------------------------------------------------------------
    // Computed: chart data (for Chart.js)
    // ----------------------------------------------------------------

    #[Computed]
    public function chartData(): array
    {
        $rows = DailyStat::inRange($this->periodRange['from'], $this->periodRange['to'])->get();
        $compareRows = $this->compareRange
            ? DailyStat::inRange($this->compareRange['from'], $this->compareRange['to'])->get()
            : collect();

        // Fill every date in range (gaps = 0)
        $from = $this->periodRange['from'];
        $to = $this->periodRange['to'];
        $days = (int) $from->diffInDays($to);
        $labels = [];
        $revenue = [];
        $orders = [];
        $newUsers = [];
        $compareRevenue = [];

        for ($i = 0; $i <= $days; $i++) {
            $date = $from->copy()->addDays($i)->format('Y-m-d');
            $labels[] = $from->copy()->addDays($i)->format('d M');

            $row = $rows->firstWhere('date', fn ($d) => Carbon::parse($d)->format('Y-m-d') === $date)
                ?? $rows->first(fn ($r) => $r->date->format('Y-m-d') === $date);

            $revenue[] = $row ? (float) $row->revenue : 0;
            $orders[] = $row ? (int) $row->orders_count : 0;
            $newUsers[] = $row ? (int) $row->new_users : 0;
        }

        // Comparison series — align by offset (same day-of-period, different dates)
        if ($compareRows->isNotEmpty()) {
            $cFrom = $this->compareRange['from'];
            for ($i = 0; $i <= $days; $i++) {
                $cDate = $cFrom->copy()->addDays($i)->format('Y-m-d');
                $row = $compareRows->first(fn ($r) => $r->date->format('Y-m-d') === $cDate);
                $compareRevenue[] = $row ? (float) $row->revenue : 0;
            }
        }

        return compact('labels', 'revenue', 'orders', 'newUsers', 'compareRevenue');
    }

    // ----------------------------------------------------------------
    // Computed: daily breakdown table
    // ----------------------------------------------------------------

    #[Computed]
    public function dailyRows(): \Illuminate\Database\Eloquent\Collection
    {
        return DailyStat::inRange($this->periodRange['from'], $this->periodRange['to'])
            ->get()
            ->sortByDesc('date');
    }

    // ----------------------------------------------------------------
    // Computed: revenue by license + tier breakdown
    // ----------------------------------------------------------------

    #[Computed]
    public function licenseBreakdown(): array
    {
        return app(DailyStatsService::class)->revenueByLicense(
            $this->periodRange['from'],
            $this->periodRange['to'],
        );
    }

    #[Computed]
    public function tierBreakdown(): array
    {
        return app(DailyStatsService::class)->ordersByTier(
            $this->periodRange['from'],
            $this->periodRange['to'],
        );
    }

    // ----------------------------------------------------------------
    // Actions: period switching
    // ----------------------------------------------------------------

    public function setPeriod(string $preset): void
    {
        $this->period = $preset;
        $this->resetComputed();
    }

    public function setCompare(string $mode): void
    {
        $this->compareMode = $mode;
        $this->resetComputed();
    }

    public function applyCustomRange(string $from, string $to): void
    {
        $this->customFrom = $from;
        $this->customTo = $to;
        $this->period = 'custom';
        $this->resetComputed();
    }

    private function resetComputed(): void
    {
        unset($this->periodRange, $this->compareRange, $this->stats, $this->compareStats,
            $this->chartData, $this->dailyRows, $this->licenseBreakdown, $this->tierBreakdown);
    }

    // ----------------------------------------------------------------
    // Helpers for the view
    // ----------------------------------------------------------------

    public function periodLabel(): string
    {
        $from = $this->periodRange['from'];
        $to = $this->periodRange['to'];

        if ($from->format('Y-m-d') === $to->format('Y-m-d')) {
            return $from->translatedFormat('j F Y');
        }

        if ($from->year !== $to->year) {
            return $from->translatedFormat('j M Y').' – '.$to->translatedFormat('j M Y');
        }

        return $from->translatedFormat('j M').' – '.$to->translatedFormat('j M Y');
    }

    public function comparePeriodLabel(): string
    {
        if ($this->compareRange === null) {
            return '';
        }

        $from = $this->compareRange['from'];
        $to = $this->compareRange['to'];

        return $from->translatedFormat('j M').' – '.$to->translatedFormat('j M Y');
    }

    /**
     * Calculate % delta between current and comparison value.
     * Returns null if comparison is unavailable.
     */
    public function delta(string $key): ?float
    {
        if ($this->compareStats === null) {
            return null;
        }

        $current = $this->stats[$key] ?? 0;
        $compare = $this->compareStats[$key] ?? 0;

        if ($compare == 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $compare) / $compare) * 100, 1);
    }

    public static function getNavigationLabel(): string
    {
        return 'Statistieken';
    }
}
