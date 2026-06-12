<?php

namespace App\Filament\Widgets;

use App\Models\CreditLedger;
use App\Models\DailyStat;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationCreditLedger;
use App\Models\User;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StatsOverview extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $startDate = Carbon::parse($this->filters['startDate'] ?? now()->startOfMonth()->format('Y-m-d'))->startOfDay();
        $endDate = Carbon::parse($this->filters['endDate'] ?? now()->format('Y-m-d'))->endOfDay();
        $prevStartDate = Carbon::parse($this->filters['previousStartDate'] ?? now()->subMonth()->startOfMonth()->format('Y-m-d'))->startOfDay();
        $prevEndDate = Carbon::parse($this->filters['previousEndDate'] ?? now()->subDay()->format('Y-m-d'))->endOfDay();
        $mode = $this->filters['mode'] ?? 'month';

        // Date-sensitive stats
        $totalUsersInPeriod = User::whereBetween('created_at', [$startDate, $endDate])->count();
        $totalUsersPrevPeriod = User::whereBetween('created_at', [$prevStartDate, $prevEndDate])->count();

        $revenueInPeriod = Order::where('status', 'paid')
            ->whereBetween('paid_at', [$startDate, $endDate])
            ->sum('net_amount');

        $revenuePrevPeriod = Order::where('status', 'paid')
            ->whereBetween('paid_at', [$prevStartDate, $prevEndDate])
            ->sum('net_amount');

        $activeUsersInPeriod = DailyStat::whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->sum('active_users');

        $activeUsersPrevPeriod = DailyStat::whereBetween('date', [$prevStartDate->toDateString(), $prevEndDate->toDateString()])
            ->sum('active_users');

        $creditsPurchased = CreditLedger::where('reason', 'purchase')
                ->whereBetween('created_at', [$startDate, $endDate])->sum('delta')
            + OrganizationCreditLedger::where('reason', 'purchase')
                ->whereBetween('created_at', [$startDate, $endDate])->sum('delta');

        $creditsSpent = abs(
            CreditLedger::where('delta', '<', 0)
                ->whereBetween('created_at', [$startDate, $endDate])->sum('delta')
            + OrganizationCreditLedger::where('delta', '<', 0)
                ->whereBetween('created_at', [$startDate, $endDate])->sum('delta')
        );

        $creditsPurchasedPrev = CreditLedger::where('reason', 'purchase')
                ->whereBetween('created_at', [$prevStartDate, $prevEndDate])->sum('delta')
            + OrganizationCreditLedger::where('reason', 'purchase')
                ->whereBetween('created_at', [$prevStartDate, $prevEndDate])->sum('delta');

        $creditsSpentPrev = abs(
            CreditLedger::where('delta', '<', 0)
                ->whereBetween('created_at', [$prevStartDate, $prevEndDate])->sum('delta')
            + OrganizationCreditLedger::where('delta', '<', 0)
                ->whereBetween('created_at', [$prevStartDate, $prevEndDate])->sum('delta')
        );

        // Non-date-sensitive stats
        $pendingJobs = DB::table('jobs')->count();
        $failedJobsToday = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->startOfDay())
            ->count();

        $periodLabel = $this->getPeriodComparisonLabel($mode);

        return [
            $this->buildComparisonStat(
                'Nieuwe gebruikers',
                $totalUsersInPeriod,
                $totalUsersPrevPeriod,
                $periodLabel,
                'heroicon-m-user-plus',
                'primary',
            ),

            $this->buildComparisonStat(
                'Omzet',
                $revenueInPeriod,
                $revenuePrevPeriod,
                $periodLabel,
                'heroicon-m-currency-euro',
                'success',
                true,
            ),

            $this->buildComparisonStat(
                'Actieve gebruikers',
                $activeUsersInPeriod,
                $activeUsersPrevPeriod,
                $periodLabel,
                'heroicon-m-users',
                'info',
            ),

            $this->buildCreditsStat(
                $creditsPurchased,
                $creditsSpent,
                $creditsPurchasedPrev,
                $creditsSpentPrev,
                $periodLabel,
            ),

            Stat::make('Queue Status', $pendingJobs > 0 ? "{$pendingJobs} pending" : 'Idle')
                ->description($failedJobsToday > 0 ? "{$failedJobsToday} failed vandaag" : 'Geen fouten vandaag')
                ->descriptionIcon($failedJobsToday > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($failedJobsToday > 0 ? 'danger' : 'success'),

            Stat::make('Organisaties', number_format(Organization::count()))
                ->description('Totaal aantal organisaties')
                ->descriptionIcon('heroicon-m-building-office')
                ->color('gray'),
        ];
    }

    protected function buildComparisonStat(
        string $label,
        int|float $current,
        int|float $previous,
        string $periodLabel,
        string $icon,
        string $baseColor,
        bool $isCurrency = false,
    ): Stat {
        $displayValue = $isCurrency
            ? '€' . number_format($current, 2)
            : number_format($current);

        $delta = $current - $previous;

        if ($isCurrency) {
            $deltaFormatted = ($delta >= 0 ? '+' : '') . '€' . number_format(abs($delta), 2);
            if ($delta < 0) {
                $deltaFormatted = '-€' . number_format(abs($delta), 2);
            }
        } else {
            $deltaFormatted = ($delta >= 0 ? '+' : '') . number_format($delta);
        }

        $description = "{$deltaFormatted} vs {$periodLabel}";
        $isPositive = $delta >= 0;

        return Stat::make($label, $displayValue)
            ->description($description)
            ->descriptionIcon($isPositive ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->color($isPositive ? 'success' : 'danger');
    }

    protected function buildCreditsStat(
        int|float $purchased,
        int|float $spent,
        int|float $purchasedPrev,
        int|float $spentPrev,
        string $periodLabel,
    ): Stat {
        $netCurrent = $purchased - $spent;
        $netPrev = $purchasedPrev - $spentPrev;
        $delta = $netCurrent - $netPrev;

        $deltaFormatted = ($delta >= 0 ? '+' : '') . number_format($delta);
        $description = "{$deltaFormatted} netto vs {$periodLabel}";
        $isPositive = $delta >= 0;

        return Stat::make('Credits Flow', '+' . number_format($purchased) . ' / -' . number_format($spent))
            ->description($description)
            ->descriptionIcon($isPositive ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->color($isPositive ? 'success' : 'danger');
    }

    protected function getPeriodComparisonLabel(string $mode): string
    {
        return match ($mode) {
            'day' => 'vorige dag',
            'week' => 'vorige week',
            'month' => 'vorige maand',
            'year' => 'vorig jaar',
            'custom' => 'vorige periode',
            default => 'vorige periode',
        };
    }
}
