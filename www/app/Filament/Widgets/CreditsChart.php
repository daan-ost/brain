<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\HasDatePeriodChart;
use App\Models\CreditLedger;
use App\Models\OrganizationCreditLedger;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class CreditsChart extends ChartWidget
{
    use HasDatePeriodChart;
    use InteractsWithPageFilters;

    protected static ?string $heading = 'Credits Flow';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 1;

    public function getHeading(): ?string
    {
        return $this->getDynamicHeading('Credits Flow');
    }

    protected function getData(): array
    {
        [$startDate, $endDate, $mode] = $this->getChartPeriod();
        $groupFormat = $this->getGroupFormat($mode, $startDate, $endDate);

        $dateKeys = $this->generateDateKeys($groupFormat, $startDate, $endDate);
        $labels = $this->generateLabels($groupFormat, $startDate, $endDate);

        $selectExpr = $this->getSelectExpression($groupFormat);

        $mergePeriodData = function (array $a, array $b): array {
            foreach ($b as $key => $value) {
                $a[$key] = ($a[$key] ?? 0) + $value;
            }
            return $a;
        };

        $purchasedData = $mergePeriodData(
            CreditLedger::where('reason', 'purchase')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw("{$selectExpr}, SUM(delta) as total")
                ->groupBy('period_key')->pluck('total', 'period_key')->toArray(),
            OrganizationCreditLedger::where('reason', 'purchase')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw("{$selectExpr}, SUM(delta) as total")
                ->groupBy('period_key')->pluck('total', 'period_key')->toArray()
        );

        $spentData = $mergePeriodData(
            CreditLedger::where('delta', '<', 0)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw("{$selectExpr}, ABS(SUM(delta)) as total")
                ->groupBy('period_key')->pluck('total', 'period_key')->toArray(),
            OrganizationCreditLedger::where('delta', '<', 0)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw("{$selectExpr}, ABS(SUM(delta)) as total")
                ->groupBy('period_key')->pluck('total', 'period_key')->toArray()
        );

        $purchasedValues = $this->mapDataToKeys($dateKeys, $purchasedData);
        $spentValues = $this->mapDataToKeys($dateKeys, $spentData);

        return [
            'datasets' => [
                [
                    'label' => 'Gekocht',
                    'data' => $purchasedValues,
                    'backgroundColor' => 'rgba(34, 197, 94, 0.5)',
                    'borderColor' => 'rgb(34, 197, 94)',
                ],
                [
                    'label' => 'Uitgegeven',
                    'data' => $spentValues,
                    'backgroundColor' => 'rgba(239, 68, 68, 0.5)',
                    'borderColor' => 'rgb(239, 68, 68)',
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
