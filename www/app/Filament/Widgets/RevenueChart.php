<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\HasDatePeriodChart;
use App\Models\Order;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class RevenueChart extends ChartWidget
{
    use HasDatePeriodChart;
    use InteractsWithPageFilters;

    protected static ?string $heading = 'Omzet';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): ?string
    {
        return $this->getDynamicHeading('Omzet');
    }

    protected function getData(): array
    {
        [$startDate, $endDate, $mode] = $this->getChartPeriod();
        $groupFormat = $this->getGroupFormat($mode, $startDate, $endDate);

        $dateKeys = $this->generateDateKeys($groupFormat, $startDate, $endDate);
        $labels = $this->generateLabels($groupFormat, $startDate, $endDate);

        $selectExpr = $this->getSelectExpressionForColumn($groupFormat, 'paid_at');

        $revenueData = Order::where('status', 'paid')
            ->whereBetween('paid_at', [$startDate, $endDate])
            ->selectRaw("{$selectExpr}, SUM(net_amount) as total")
            ->groupBy('period_key')
            ->pluck('total', 'period_key')
            ->toArray();

        $values = $this->mapDataToKeys($dateKeys, $revenueData);

        return [
            'datasets' => [
                [
                    'label' => 'Omzet',
                    'data' => $values,
                    'backgroundColor' => 'rgba(59, 130, 246, 0.5)',
                    'borderColor' => 'rgb(59, 130, 246)',
                    'fill' => true,
                ],
            ],
            'labels' => $labels->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
