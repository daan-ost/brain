<?php

namespace App\Livewire\Concerns;

use Carbon\Carbon;
use Livewire\Attributes\Url;

trait WithPeriodFilter
{
    #[Url(as: 'period')]
    public string $periodType = 'year';

    #[Url(as: 'date')]
    public ?string $periodDate = null;

    #[Url(as: 'from')]
    public ?string $dateFrom = null;

    #[Url(as: 'to')]
    public ?string $dateTo = null;

    public function mountWithPeriodFilter(): void
    {
        $allowedPeriodTypes = ['day', 'week', 'month', 'quarter', 'year', 'custom'];

        if (! in_array($this->periodType, $allowedPeriodTypes, true)) {
            $this->periodType = 'year';
        }

        if ($this->periodDate) {
            try {
                Carbon::parse($this->periodDate);
            } catch (\Exception) {
                $this->periodDate = now()->format('Y-m-d');
            }
        } else {
            $this->periodDate = now()->format('Y-m-d');
        }

        if ($this->periodType !== 'custom') {
            $this->computeDateRange();
        }
    }

    public function setPeriodType(string $type): void
    {
        $this->periodType = $type;
        $this->periodDate = now()->format('Y-m-d');

        if ($type !== 'custom') {
            $this->computeDateRange();
        }

        $this->resetPageIfAvailable();
    }

    public function previousPeriod(): void
    {
        $this->shiftPeriod('sub');
    }

    public function nextPeriod(): void
    {
        $this->shiftPeriod('add');
    }

    private function shiftPeriod(string $direction): void
    {
        $date = Carbon::parse($this->periodDate);

        $this->periodDate = match ($this->periodType) {
            'day' => $date->{$direction.'Day'}()->format('Y-m-d'),
            'week' => $date->{$direction.'Week'}()->format('Y-m-d'),
            'month' => $date->{$direction.'Month'}()->format('Y-m-d'),
            'quarter' => $date->{$direction.'Months'}(3)->format('Y-m-d'),
            'year' => $date->{$direction.'Year'}()->format('Y-m-d'),
            default => $this->periodDate,
        };

        $this->computeDateRange();
        $this->resetPageIfAvailable();
    }

    private function resetPageIfAvailable(): void
    {
        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    public function getPeriodLabel(): string
    {
        $date = Carbon::parse($this->periodDate);

        return match ($this->periodType) {
            'day' => $date->translatedFormat('j M Y'),
            'week' => $date->copy()->startOfWeek()->translatedFormat('j M').' - '.$date->copy()->endOfWeek()->translatedFormat('j M Y'),
            'month' => $date->translatedFormat('M Y'),
            'quarter' => 'Q'.ceil($date->month / 3).' '.$date->year,
            'year' => (string) $date->year,
            default => '',
        };
    }

    private function computeDateRange(): void
    {
        $date = Carbon::parse($this->periodDate);

        [$this->dateFrom, $this->dateTo] = match ($this->periodType) {
            'day' => [$date->format('Y-m-d'), $date->format('Y-m-d')],
            'week' => [$date->copy()->startOfWeek()->format('Y-m-d'), $date->copy()->endOfWeek()->format('Y-m-d')],
            'month' => [$date->copy()->startOfMonth()->format('Y-m-d'), $date->copy()->endOfMonth()->format('Y-m-d')],
            'quarter' => [$date->copy()->firstOfQuarter()->format('Y-m-d'), $date->copy()->lastOfQuarter()->format('Y-m-d')],
            'year' => [$date->copy()->startOfYear()->format('Y-m-d'), $date->copy()->endOfYear()->format('Y-m-d')],
            default => [$this->dateFrom, $this->dateTo],
        };
    }

    protected function periodDateColumn(): string
    {
        return 'date';
    }

    public function applyPeriodFilter($query): mixed
    {
        if ($this->dateFrom && $this->dateTo && $this->dateFrom > $this->dateTo) {
            [$this->dateFrom, $this->dateTo] = [$this->dateTo, $this->dateFrom];
        }

        $column = $this->periodDateColumn();

        return $query
            ->when($this->dateFrom, fn ($q) => $q->where($column, '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->where($column, '<=', $this->dateTo));
    }
}
