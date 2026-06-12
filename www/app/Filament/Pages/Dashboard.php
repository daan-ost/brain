<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFilters;
use Livewire\Attributes\On;

class Dashboard extends BaseDashboard
{
    use HasFilters;

    protected static string $view = 'filament.pages.dashboard';

    protected bool $persistsFiltersInSession = true;

    protected static ?string $navigationIcon = 'heroicon-o-home';

    public function mount(): void
    {
        // Initialize filters from session or defaults
        $session = session()->get('dashboard_date_selector', []);

        if (empty($this->filters)) {
            $this->filters = $this->resolveFiltersFromSession($session);
        }
    }

    #[On('dashboard-date-changed')]
    public function handleDateChanged(
        string $startDate,
        string $endDate,
        string $previousStartDate,
        string $previousEndDate,
        string $mode = 'month',
    ): void {
        $this->filters = compact('startDate', 'endDate', 'previousStartDate', 'previousEndDate', 'mode');

        if ($this->persistsFiltersInSession()) {
            session()->put($this->getFiltersSessionKey(), $this->filters);
        }
    }

    protected function resolveFiltersFromSession(array $session): array
    {
        $mode = $session['mode'] ?? 'month';
        $selectedDate = $session['selectedDate'] ?? now()->format('Y-m-d');
        $date = \Carbon\Carbon::parse($selectedDate);

        if ($mode === 'custom') {
            $customStart = $session['customStart'] ?? now()->subMonth()->format('Y-m-d');
            $customEnd = $session['customEnd'] ?? now()->format('Y-m-d');
            $start = \Carbon\Carbon::parse($customStart);
            $end = \Carbon\Carbon::parse($customEnd);
            $days = $start->diffInDays($end);

            return [
                'startDate' => $customStart,
                'endDate' => $customEnd,
                'previousStartDate' => $start->copy()->subDays($days + 1)->format('Y-m-d'),
                'previousEndDate' => $start->copy()->subDay()->format('Y-m-d'),
                'mode' => 'custom',
            ];
        }

        $startDate = match ($mode) {
            'day' => $date->copy()->startOfDay()->format('Y-m-d'),
            'week' => $date->copy()->startOfWeek()->format('Y-m-d'),
            'month' => $date->copy()->startOfMonth()->format('Y-m-d'),
            'year' => $date->copy()->startOfYear()->format('Y-m-d'),
            default => $date->format('Y-m-d'),
        };

        $endDate = match ($mode) {
            'day' => $date->copy()->endOfDay()->format('Y-m-d'),
            'week' => $date->copy()->endOfWeek()->format('Y-m-d'),
            'month' => $date->copy()->endOfMonth()->format('Y-m-d'),
            'year' => $date->copy()->endOfYear()->format('Y-m-d'),
            default => $date->format('Y-m-d'),
        };

        $startCarbon = \Carbon\Carbon::parse($startDate);
        $endCarbon = \Carbon\Carbon::parse($endDate);

        $previousStartDate = match ($mode) {
            'day' => $startCarbon->copy()->subDay()->format('Y-m-d'),
            'week' => $startCarbon->copy()->subWeek()->format('Y-m-d'),
            'month' => $startCarbon->copy()->subMonth()->format('Y-m-d'),
            'year' => $startCarbon->copy()->subYear()->format('Y-m-d'),
            default => $startCarbon->format('Y-m-d'),
        };

        $previousEndDate = match ($mode) {
            'day' => $endCarbon->copy()->subDay()->format('Y-m-d'),
            'week' => $endCarbon->copy()->subWeek()->format('Y-m-d'),
            'month' => $endCarbon->copy()->subMonth()->format('Y-m-d'),
            'year' => $endCarbon->copy()->subYear()->format('Y-m-d'),
            default => $endCarbon->format('Y-m-d'),
        };

        return [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'previousStartDate' => $previousStartDate,
            'previousEndDate' => $previousEndDate,
            'mode' => $mode,
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }

    public function getColumns(): int|string|array
    {
        return 2;
    }
}
