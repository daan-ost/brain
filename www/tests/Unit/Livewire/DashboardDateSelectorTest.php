<?php

declare(strict_types=1);

use App\Livewire\DashboardDateSelector;
use Carbon\Carbon;
use Livewire\Livewire;

describe('DashboardDateSelector', function () {
    beforeEach(function () {
        Carbon::setTestNow(Carbon::parse('2026-02-01'));
        session()->flush();
    });

    afterEach(function () {
        Carbon::setTestNow();
    });

    describe('mount', function () {
        it('initializes with default month mode', function () {
            Livewire::test(DashboardDateSelector::class)
                ->assertSet('mode', 'month')
                ->assertSet('selectedDate', '2026-02-01');
        });

        it('restores state from session', function () {
            session()->put('dashboard_date_selector', [
                'mode' => 'week',
                'selectedDate' => '2026-01-15',
                'customStart' => '2026-01-01',
                'customEnd' => '2026-01-31',
            ]);

            Livewire::test(DashboardDateSelector::class)
                ->assertSet('mode', 'week')
                ->assertSet('selectedDate', '2026-01-15');
        });
    });

    describe('period label', function () {
        it('shows day format correctly', function () {
            $component = Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'day')
                ->set('selectedDate', '2026-02-01');

            expect($component->get('periodLabel'))->toContain('2026');
        });

        it('shows month format correctly', function () {
            $component = Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'month')
                ->set('selectedDate', '2026-02-01');

            expect($component->get('periodLabel'))->toContain('2026');
        });

        it('shows year format correctly', function () {
            $component = Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'year')
                ->set('selectedDate', '2026-02-01');

            expect($component->get('periodLabel'))->toBe('2026');
        });

        it('shows empty string for custom mode', function () {
            $component = Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'custom')
                ->set('selectedDate', '2026-02-01');

            expect($component->get('periodLabel'))->toBe('');
        });
    });

    describe('navigation', function () {
        it('navigates to previous day', function () {
            Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'day')
                ->set('selectedDate', '2026-02-01')
                ->call('previous')
                ->assertSet('selectedDate', '2026-01-31');
        });

        it('navigates to next day', function () {
            Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'day')
                ->set('selectedDate', '2026-01-30')
                ->call('next')
                ->assertSet('selectedDate', '2026-01-31');
        });

        it('navigates to previous week', function () {
            Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'week')
                ->set('selectedDate', '2026-02-01')
                ->call('previous')
                ->assertSet('selectedDate', '2026-01-25');
        });

        it('navigates to previous month', function () {
            Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'month')
                ->set('selectedDate', '2026-02-01')
                ->call('previous')
                ->assertSet('selectedDate', '2026-01-01');
        });

        it('navigates to previous year', function () {
            Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'year')
                ->set('selectedDate', '2026-02-01')
                ->call('previous')
                ->assertSet('selectedDate', '2025-02-01');
        });

        it('disables next button when current period includes today', function () {
            // Today is 2026-02-01, month mode includes today
            $component = Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'month')
                ->set('selectedDate', '2026-02-01');

            expect($component->get('isNextDisabled'))->toBeTrue();
        });

        it('enables next button for past periods', function () {
            $component = Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'month')
                ->set('selectedDate', '2026-01-01');

            expect($component->get('isNextDisabled'))->toBeFalse();
        });

        it('does not navigate forward when next is disabled', function () {
            Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'day')
                ->set('selectedDate', '2026-02-01')
                ->call('next')
                ->assertSet('selectedDate', '2026-02-01');
        });
    });

    describe('date range calculation', function () {
        it('calculates correct day range', function () {
            $component = Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'day')
                ->set('selectedDate', '2026-02-01');

            expect($component->get('startDate'))->toBe('2026-02-01');
            expect($component->get('endDate'))->toBe('2026-02-01');
        });

        it('calculates correct week range', function () {
            // 2026-02-01 is a Sunday. Monday of that week is 2026-01-26
            $component = Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'week')
                ->set('selectedDate', '2026-02-01');

            $startDate = $component->get('startDate');
            $endDate = $component->get('endDate');

            expect(Carbon::parse($startDate)->dayOfWeekIso)->toBe(1); // Monday
            expect(Carbon::parse($endDate)->dayOfWeekIso)->toBe(7); // Sunday
        });

        it('calculates correct month range', function () {
            $component = Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'month')
                ->set('selectedDate', '2026-02-15');

            expect($component->get('startDate'))->toBe('2026-02-01');
            expect($component->get('endDate'))->toBe('2026-02-28');
        });

        it('calculates correct year range', function () {
            $component = Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'year')
                ->set('selectedDate', '2026-06-15');

            expect($component->get('startDate'))->toBe('2026-01-01');
            expect($component->get('endDate'))->toBe('2026-12-31');
        });

        it('uses custom date inputs for custom mode', function () {
            $component = Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'custom')
                ->set('customStart', '2026-01-01')
                ->set('customEnd', '2026-03-31');

            expect($component->get('startDate'))->toBe('2026-01-01');
            expect($component->get('endDate'))->toBe('2026-03-31');
        });
    });

    describe('previous period calculation', function () {
        it('calculates previous day correctly', function () {
            $component = Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'day')
                ->set('selectedDate', '2026-02-01');

            expect($component->get('previousStartDate'))->toBe('2026-01-31');
            expect($component->get('previousEndDate'))->toBe('2026-01-31');
        });

        it('calculates previous month correctly', function () {
            $component = Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'month')
                ->set('selectedDate', '2026-02-15');

            expect($component->get('previousStartDate'))->toBe('2026-01-01');
            expect($component->get('previousEndDate'))->toBe('2026-01-28');
        });

        it('calculates previous year correctly', function () {
            $component = Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'year')
                ->set('selectedDate', '2026-06-15');

            expect($component->get('previousStartDate'))->toBe('2025-01-01');
            expect($component->get('previousEndDate'))->toBe('2025-12-31');
        });

        it('calculates previous custom period correctly', function () {
            $component = Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'custom')
                ->set('customStart', '2026-02-01')
                ->set('customEnd', '2026-02-28');

            // 28-day range, so previous period is the preceding 28 days
            $previousStart = $component->get('previousStartDate');
            $previousEnd = $component->get('previousEndDate');

            expect($previousEnd)->toBe('2026-01-31');
            expect((int) Carbon::parse($previousStart)->diffInDays(Carbon::parse($previousEnd)))->toBe(27);
        });
    });

    describe('custom range validation', function () {
        it('shows error when range exceeds 1 year', function () {
            Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'custom')
                ->set('customStart', '2025-01-01')
                ->set('customEnd', '2026-02-01')
                ->assertSet('validationError', 'Het datumbereik mag maximaal 1 jaar zijn.');
        });

        it('shows error when end date is before start date', function () {
            Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'custom')
                ->set('customStart', '2026-03-01')
                ->set('customEnd', '2026-02-01')
                ->assertSet('validationError', 'Einddatum moet na de startdatum liggen.');
        });

        it('clears error for valid range', function () {
            Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'custom')
                ->set('customStart', '2026-01-01')
                ->set('customEnd', '2026-06-01')
                ->assertSet('validationError', null);
        });
    });

    describe('event dispatching', function () {
        it('dispatches event when mode changes', function () {
            Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'day')
                ->assertDispatched('dashboard-date-changed');
        });

        it('dispatches event when navigating', function () {
            Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'month')
                ->set('selectedDate', '2026-01-15')
                ->call('previous')
                ->assertDispatched('dashboard-date-changed');
        });

        it('dispatches event with correct data structure', function () {
            Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'month')
                ->set('selectedDate', '2026-02-01')
                ->call('previous')
                ->assertDispatched('dashboard-date-changed', fn ($name, $params) => isset($params['startDate'])
                    && isset($params['endDate'])
                    && isset($params['previousStartDate'])
                    && isset($params['previousEndDate'])
                    && isset($params['mode'])
                );
        });
    });

    describe('session persistence', function () {
        it('persists state to session on mode change', function () {
            Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'week');

            $session = session()->get('dashboard_date_selector');
            expect($session['mode'])->toBe('week');
        });

        it('persists state to session on navigation', function () {
            Livewire::test(DashboardDateSelector::class)
                ->set('mode', 'month')
                ->set('selectedDate', '2026-02-01')
                ->call('previous');

            $session = session()->get('dashboard_date_selector');
            expect($session['selectedDate'])->toBe('2026-01-01');
        });
    });
});
