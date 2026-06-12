<?php

namespace App\Livewire;

use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;

class DashboardDateSelector extends Component
{
    public string $mode = 'month';

    public string $selectedDate;

    public ?string $customStart = null;

    public ?string $customEnd = null;

    public ?string $validationError = null;

    public function mount(): void
    {
        $session = session()->get('dashboard_date_selector', []);

        $this->mode = $session['mode'] ?? 'month';
        $this->selectedDate = $session['selectedDate'] ?? now()->format('Y-m-d');
        $this->customStart = $session['customStart'] ?? now()->subMonth()->format('Y-m-d');
        $this->customEnd = $session['customEnd'] ?? now()->format('Y-m-d');
    }

    public function updatedMode(): void
    {
        $this->validationError = null;
        $this->persistAndDispatch();
    }

    public function updatedCustomStart(): void
    {
        if ($this->mode === 'custom') {
            $this->validateCustomRange();
            $this->persistAndDispatch();
        }
    }

    public function updatedCustomEnd(): void
    {
        if ($this->mode === 'custom') {
            $this->validateCustomRange();
            $this->persistAndDispatch();
        }
    }

    public function previous(): void
    {
        $date = Carbon::parse($this->selectedDate);

        $this->selectedDate = match ($this->mode) {
            'day' => $date->subDay()->format('Y-m-d'),
            'week' => $date->subWeek()->format('Y-m-d'),
            'month' => $date->subMonth()->format('Y-m-d'),
            'year' => $date->subYear()->format('Y-m-d'),
            default => $this->selectedDate,
        };

        $this->persistAndDispatch();
    }

    public function next(): void
    {
        if ($this->isNextDisabled()) {
            return;
        }

        $date = Carbon::parse($this->selectedDate);

        $this->selectedDate = match ($this->mode) {
            'day' => $date->addDay()->format('Y-m-d'),
            'week' => $date->addWeek()->format('Y-m-d'),
            'month' => $date->addMonth()->format('Y-m-d'),
            'year' => $date->addYear()->format('Y-m-d'),
            default => $this->selectedDate,
        };

        $this->persistAndDispatch();
    }

    #[Computed]
    public function isNextDisabled(): bool
    {
        $date = Carbon::parse($this->selectedDate);

        return match ($this->mode) {
            'day' => $date->isToday() || $date->isFuture(),
            'week' => $date->copy()->endOfWeek()->isFuture(),
            'month' => $date->copy()->endOfMonth()->isFuture(),
            'year' => $date->copy()->endOfYear()->isFuture(),
            default => false,
        };
    }

    #[Computed]
    public function periodLabel(): string
    {
        $date = Carbon::parse($this->selectedDate);

        return match ($this->mode) {
            'day' => $date->translatedFormat('j F Y'),
            'week' => $date->copy()->startOfWeek()->translatedFormat('j M') . ' – ' . $date->copy()->endOfWeek()->translatedFormat('j M Y'),
            'month' => ucfirst($date->translatedFormat('F Y')),
            'year' => $date->format('Y'),
            'custom' => '',
            default => '',
        };
    }

    #[Computed]
    public function startDate(): string
    {
        if ($this->mode === 'custom') {
            return $this->customStart ?? now()->subMonth()->format('Y-m-d');
        }

        $date = Carbon::parse($this->selectedDate);

        return match ($this->mode) {
            'day' => $date->copy()->startOfDay()->format('Y-m-d'),
            'week' => $date->copy()->startOfWeek()->format('Y-m-d'),
            'month' => $date->copy()->startOfMonth()->format('Y-m-d'),
            'year' => $date->copy()->startOfYear()->format('Y-m-d'),
            default => $date->format('Y-m-d'),
        };
    }

    #[Computed]
    public function endDate(): string
    {
        if ($this->mode === 'custom') {
            return $this->customEnd ?? now()->format('Y-m-d');
        }

        $date = Carbon::parse($this->selectedDate);

        return match ($this->mode) {
            'day' => $date->copy()->endOfDay()->format('Y-m-d'),
            'week' => $date->copy()->endOfWeek()->format('Y-m-d'),
            'month' => $date->copy()->endOfMonth()->format('Y-m-d'),
            'year' => $date->copy()->endOfYear()->format('Y-m-d'),
            default => $date->format('Y-m-d'),
        };
    }

    #[Computed]
    public function previousStartDate(): string
    {
        $start = Carbon::parse($this->startDate);
        $end = Carbon::parse($this->endDate);

        if ($this->mode === 'custom') {
            $days = (int) $start->diffInDays($end);

            return $start->copy()->subDays($days + 1)->format('Y-m-d');
        }

        return match ($this->mode) {
            'day' => $start->copy()->subDay()->format('Y-m-d'),
            'week' => $start->copy()->subWeek()->format('Y-m-d'),
            'month' => $start->copy()->subMonth()->format('Y-m-d'),
            'year' => $start->copy()->subYear()->format('Y-m-d'),
            default => $start->format('Y-m-d'),
        };
    }

    #[Computed]
    public function previousEndDate(): string
    {
        $start = Carbon::parse($this->startDate);
        $end = Carbon::parse($this->endDate);

        if ($this->mode === 'custom') {
            $days = (int) $start->diffInDays($end);

            return $start->copy()->subDay()->format('Y-m-d');
        }

        return match ($this->mode) {
            'day' => $end->copy()->subDay()->format('Y-m-d'),
            'week' => $end->copy()->subWeek()->format('Y-m-d'),
            'month' => $end->copy()->subMonth()->format('Y-m-d'),
            'year' => $end->copy()->subYear()->format('Y-m-d'),
            default => $end->format('Y-m-d'),
        };
    }

    protected function validateCustomRange(): void
    {
        $this->validationError = null;

        if (! $this->customStart || ! $this->customEnd) {
            return;
        }

        $start = Carbon::parse($this->customStart);
        $end = Carbon::parse($this->customEnd);

        if ($end->isBefore($start)) {
            $this->validationError = 'Einddatum moet na de startdatum liggen.';

            return;
        }

        if ((int) $start->diffInDays($end) > 365) {
            $this->validationError = 'Het datumbereik mag maximaal 1 jaar zijn.';
        }
    }

    protected function persistAndDispatch(): void
    {
        session()->put('dashboard_date_selector', [
            'mode' => $this->mode,
            'selectedDate' => $this->selectedDate,
            'customStart' => $this->customStart,
            'customEnd' => $this->customEnd,
        ]);

        $this->dispatch('dashboard-date-changed',
            startDate: $this->startDate,
            endDate: $this->endDate,
            previousStartDate: $this->previousStartDate,
            previousEndDate: $this->previousEndDate,
            mode: $this->mode,
        );
    }

    public function render()
    {
        return view('livewire.dashboard-date-selector');
    }
}
