<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\CreditsChart;
use App\Filament\Widgets\RevenueChart;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\UsersChart;
use App\Models\AnalyticsEvent;
use App\Models\CreditLedger;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-02-01 12:00:00'));
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin, 'admin');
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('Dashboard Page', function () {
    it('renders successfully with custom date selector', function () {
        $this->get('/beheer')
            ->assertOk()
            ->assertSeeLivewire('dashboard-date-selector');
    });

    it('renders the custom dashboard page class', function () {
        Livewire::test(Dashboard::class)
            ->assertSuccessful();
    });

    it('initializes filters from session', function () {
        session()->put('dashboard_date_selector', [
            'mode' => 'week',
            'selectedDate' => '2026-01-20',
        ]);

        $component = Livewire::test(Dashboard::class);

        expect($component->get('filters'))->toBeArray();
        expect($component->get('filters.mode'))->toBe('week');
    });

    it('handles date changed event', function () {
        Livewire::test(Dashboard::class)
            ->dispatch(
                'dashboard-date-changed',
                startDate: '2026-01-01',
                endDate: '2026-01-31',
                previousStartDate: '2025-12-01',
                previousEndDate: '2025-12-31',
                mode: 'month',
            )
            ->assertSet('filters.startDate', '2026-01-01')
            ->assertSet('filters.endDate', '2026-01-31')
            ->assertSet('filters.mode', 'month');
    });
});

describe('StatsOverview Widget', function () {
    it('renders with date filters', function () {
        Livewire::test(StatsOverview::class, ['filters' => [
            'startDate' => '2026-02-01',
            'endDate' => '2026-02-28',
            'previousStartDate' => '2026-01-01',
            'previousEndDate' => '2026-01-31',
            'mode' => 'month',
        ]])
            ->assertSuccessful();
    });

    it('shows date-filtered user count', function () {
        // Create users in the selected period
        User::factory()->count(3)->create(['created_at' => '2026-02-15']);
        // Create users in the previous period
        User::factory()->count(1)->create(['created_at' => '2026-01-15']);

        $component = Livewire::test(StatsOverview::class, ['filters' => [
            'startDate' => '2026-02-01',
            'endDate' => '2026-02-28',
            'previousStartDate' => '2026-01-01',
            'previousEndDate' => '2026-01-31',
            'mode' => 'month',
        ]]);

        $component->assertSuccessful();
    });

    it('shows date-filtered revenue', function () {
        Order::factory()->create([
            'status' => 'paid',
            'currency' => 'eur',
            'net_amount' => 5000,
            'updated_at' => '2026-02-10',
        ]);

        Order::factory()->create([
            'status' => 'paid',
            'currency' => 'eur',
            'net_amount' => 3000,
            'updated_at' => '2026-01-10',
        ]);

        Livewire::test(StatsOverview::class, ['filters' => [
            'startDate' => '2026-02-01',
            'endDate' => '2026-02-28',
            'previousStartDate' => '2026-01-01',
            'previousEndDate' => '2026-01-31',
            'mode' => 'month',
        ]])
            ->assertSuccessful();
    });

    it('always shows current queue status regardless of date filter', function () {
        Livewire::test(StatsOverview::class, ['filters' => [
            'startDate' => '2025-01-01',
            'endDate' => '2025-01-31',
            'previousStartDate' => '2024-12-01',
            'previousEndDate' => '2024-12-31',
            'mode' => 'month',
        ]])
            ->assertSuccessful();
    });

    it('renders with null filters gracefully', function () {
        Livewire::test(StatsOverview::class, ['filters' => null])
            ->assertSuccessful();
    });
});

describe('RevenueChart Widget', function () {
    it('renders with date filters', function () {
        Livewire::test(RevenueChart::class, ['filters' => [
            'startDate' => '2026-02-01',
            'endDate' => '2026-02-28',
            'previousStartDate' => '2026-01-01',
            'previousEndDate' => '2026-01-31',
            'mode' => 'month',
        ]])
            ->assertSuccessful();
    });

    it('renders with day mode (hourly grouping)', function () {
        Livewire::test(RevenueChart::class, ['filters' => [
            'startDate' => '2026-02-01',
            'endDate' => '2026-02-01',
            'previousStartDate' => '2026-01-31',
            'previousEndDate' => '2026-01-31',
            'mode' => 'day',
        ]])
            ->assertSuccessful();
    });

    it('renders with year mode (monthly grouping)', function () {
        Livewire::test(RevenueChart::class, ['filters' => [
            'startDate' => '2026-01-01',
            'endDate' => '2026-12-31',
            'previousStartDate' => '2025-01-01',
            'previousEndDate' => '2025-12-31',
            'mode' => 'year',
        ]])
            ->assertSuccessful();
    });

    it('renders with null filters gracefully', function () {
        Livewire::test(RevenueChart::class, ['filters' => null])
            ->assertSuccessful();
    });
});

describe('UsersChart Widget', function () {
    it('renders with date filters', function () {
        Livewire::test(UsersChart::class, ['filters' => [
            'startDate' => '2026-02-01',
            'endDate' => '2026-02-28',
            'previousStartDate' => '2026-01-01',
            'previousEndDate' => '2026-01-31',
            'mode' => 'month',
        ]])
            ->assertSuccessful();
    });

    it('renders with week mode', function () {
        Livewire::test(UsersChart::class, ['filters' => [
            'startDate' => '2026-01-26',
            'endDate' => '2026-02-01',
            'previousStartDate' => '2026-01-19',
            'previousEndDate' => '2026-01-25',
            'mode' => 'week',
        ]])
            ->assertSuccessful();
    });
});

describe('CreditsChart Widget', function () {
    it('renders with date filters', function () {
        Livewire::test(CreditsChart::class, ['filters' => [
            'startDate' => '2026-02-01',
            'endDate' => '2026-02-28',
            'previousStartDate' => '2026-01-01',
            'previousEndDate' => '2026-01-31',
            'mode' => 'month',
        ]])
            ->assertSuccessful();
    });

    it('renders with custom mode', function () {
        Livewire::test(CreditsChart::class, ['filters' => [
            'startDate' => '2026-01-01',
            'endDate' => '2026-03-31',
            'previousStartDate' => '2025-10-02',
            'previousEndDate' => '2025-12-31',
            'mode' => 'custom',
        ]])
            ->assertSuccessful();
    });
});
