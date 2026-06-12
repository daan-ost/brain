<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\HasDatePeriodChart;
use App\Models\User;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class UsersChart extends ChartWidget
{
    use HasDatePeriodChart;
    use InteractsWithPageFilters;

    protected static ?string $heading = 'Nieuwe gebruikers';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    public function getHeading(): ?string
    {
        return $this->getDynamicHeading('Nieuwe gebruikers');
    }

    protected function getData(): array
    {
        [$startDate, $endDate, $mode] = $this->getChartPeriod();
        $groupFormat = $this->getGroupFormat($mode, $startDate, $endDate);

        $dateKeys = $this->generateDateKeys($groupFormat, $startDate, $endDate);
        $labels = $this->generateLabels($groupFormat, $startDate, $endDate);

        $selectExpr = $this->getSelectExpression($groupFormat);

        $userData = User::whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw("{$selectExpr}, COUNT(*) as count")
            ->groupBy('period_key')
            ->pluck('count', 'period_key')
            ->toArray();

        $values = $this->mapDataToKeys($dateKeys, $userData);

        return [
            'datasets' => [
                [
                    'label' => 'Nieuwe gebruikers',
                    'data' => $values,
                    'backgroundColor' => 'rgba(34, 197, 94, 0.5)',
                    'borderColor' => 'rgb(34, 197, 94)',
                ],
            ],
            'labels' => $labels->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
