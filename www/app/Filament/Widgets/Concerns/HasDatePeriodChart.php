<?php

namespace App\Filament\Widgets\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

trait HasDatePeriodChart
{
    protected function getChartPeriod(): array
    {
        $startDate = Carbon::parse($this->filters['startDate'] ?? now()->startOfMonth()->format('Y-m-d'))->startOfDay();
        $endDate = Carbon::parse($this->filters['endDate'] ?? now()->format('Y-m-d'))->endOfDay();
        $mode = $this->filters['mode'] ?? 'month';

        return [$startDate, $endDate, $mode];
    }

    protected function getGroupFormat(string $mode, Carbon $startDate, Carbon $endDate): string
    {
        if ($mode === 'day') {
            return 'hour';
        }

        if ($mode === 'year') {
            return 'month';
        }

        if ($mode === 'custom') {
            $days = (int) $startDate->diffInDays($endDate);

            return $days > 31 ? 'week' : 'day';
        }

        // week, month
        return 'day';
    }

    protected function generateLabels(string $groupFormat, Carbon $startDate, Carbon $endDate): Collection
    {
        return match ($groupFormat) {
            'hour' => collect(range(0, 23))->map(fn ($h) => str_pad($h, 2, '0', STR_PAD_LEFT) . ':00'),
            'day' => collect(range(0, (int) $startDate->diffInDays($endDate)))->map(
                fn ($d) => $startDate->copy()->addDays($d)->translatedFormat('j M')
            ),
            'week' => $this->generateWeekLabels($startDate, $endDate),
            'month' => collect(range(0, 11))->map(
                fn ($m) => $startDate->copy()->addMonths($m)->translatedFormat('M')
            ),
            default => collect(),
        };
    }

    protected function generateWeekLabels(Carbon $startDate, Carbon $endDate): Collection
    {
        $labels = collect();
        $current = $startDate->copy()->startOfWeek();

        while ($current->lte($endDate)) {
            $labels->push('W' . $current->weekOfYear);
            $current->addWeek();
        }

        return $labels;
    }

    protected function generateDateKeys(string $groupFormat, Carbon $startDate, Carbon $endDate): Collection
    {
        return match ($groupFormat) {
            'hour' => collect(range(0, 23)),
            'day' => collect(range(0, (int) $startDate->diffInDays($endDate)))->map(
                fn ($d) => $startDate->copy()->addDays($d)->format('Y-m-d')
            ),
            'week' => $this->generateWeekKeys($startDate, $endDate),
            'month' => collect(range(0, 11))->map(
                fn ($m) => $startDate->copy()->addMonths($m)->format('Y-m')
            ),
            default => collect(),
        };
    }

    protected function generateWeekKeys(Carbon $startDate, Carbon $endDate): Collection
    {
        $keys = collect();
        $current = $startDate->copy()->startOfWeek();

        while ($current->lte($endDate)) {
            $keys->push($current->format('o-W'));
            $current->addWeek();
        }

        return $keys;
    }

    protected function getSelectExpression(string $groupFormat): string
    {
        return match ($groupFormat) {
            'hour' => 'HOUR(created_at) as period_key',
            'day' => 'DATE(created_at) as period_key',
            'week' => "CONCAT(LEFT(YEARWEEK(created_at, 3), 4), '-', RIGHT(YEARWEEK(created_at, 3), 2)) as period_key",
            'month' => "DATE_FORMAT(created_at, '%Y-%m') as period_key",
            default => 'DATE(created_at) as period_key',
        };
    }

    protected function getSelectExpressionForColumn(string $groupFormat, string $column): string
    {
        return match ($groupFormat) {
            'hour' => "HOUR({$column}) as period_key",
            'day' => "DATE({$column}) as period_key",
            'week' => "CONCAT(LEFT(YEARWEEK({$column}, 3), 4), '-', RIGHT(YEARWEEK({$column}, 3), 2)) as period_key",
            'month' => "DATE_FORMAT({$column}, '%Y-%m') as period_key",
            default => "DATE({$column}) as period_key",
        };
    }

    protected function mapDataToKeys(Collection $dateKeys, array $rawData, float $divisor = 1): array
    {
        return $dateKeys->map(function ($key) use ($rawData, $divisor) {
            $value = $rawData[$key] ?? 0;

            return $divisor !== 1.0 ? $value / $divisor : $value;
        })->toArray();
    }

    protected function getDynamicHeading(string $baseHeading): string
    {
        $mode = $this->filters['mode'] ?? 'month';
        $startDate = Carbon::parse($this->filters['startDate'] ?? now()->startOfMonth()->format('Y-m-d'));
        $endDate = Carbon::parse($this->filters['endDate'] ?? now()->format('Y-m-d'));

        return match ($mode) {
            'day' => "{$baseHeading} – " . $startDate->translatedFormat('j F Y'),
            'week' => "{$baseHeading} – " . $startDate->translatedFormat('j M') . ' – ' . $endDate->translatedFormat('j M Y'),
            'month' => "{$baseHeading} – " . ucfirst($startDate->translatedFormat('F Y')),
            'year' => "{$baseHeading} – " . $startDate->format('Y'),
            'custom' => "{$baseHeading} – " . $startDate->translatedFormat('j M Y') . ' – ' . $endDate->translatedFormat('j M Y'),
            default => $baseHeading,
        };
    }
}
