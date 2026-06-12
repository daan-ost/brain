<?php

declare(strict_types=1);

use App\Filament\Pages\Statistics;
use App\Models\DailyStat;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-02-15 12:00:00'));
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin, 'admin');
});

afterEach(fn () => Carbon::setTestNow());

describe('Statistics Page', function () {

    it('renders successfully', function () {
        $this->get('/beheer/statistics')->assertOk();
    });

    it('renders the Livewire component', function () {
        Livewire::test(Statistics::class)->assertSuccessful();
    });

    it('defaults to 30d period', function () {
        $component = Livewire::test(Statistics::class);

        expect($component->get('period'))->toBe('30d');
        expect($component->get('compareMode'))->toBe('none');
    });

    it('can switch period preset', function () {
        Livewire::test(Statistics::class)
            ->call('setPeriod', '7d')
            ->assertSet('period', '7d');
    });

    it('can switch compare mode', function () {
        Livewire::test(Statistics::class)
            ->call('setCompare', 'previous')
            ->assertSet('compareMode', 'previous');
    });

    it('can apply custom range', function () {
        Livewire::test(Statistics::class)
            ->call('applyCustomRange', '2026-01-01', '2026-01-31')
            ->assertSet('period', 'custom')
            ->assertSet('customFrom', '2026-01-01')
            ->assertSet('customTo', '2026-01-31');
    });

    it('resolves correct date range for 7d preset', function () {
        $component = Livewire::test(Statistics::class);
        $component->call('setPeriod', '7d');

        $range = $component->instance()->periodRange;
        expect($range['from']->format('Y-m-d'))->toBe('2026-02-09')
            ->and($range['to']->format('Y-m-d'))->toBe('2026-02-15');
    });

    it('resolves compare range for previous period', function () {
        $component = Livewire::test(Statistics::class);
        $component->call('setPeriod', '7d');
        $component->call('setCompare', 'previous');

        $range = $component->instance()->compareRange;
        expect($range)->not->toBeNull()
            ->and($range['from']->format('Y-m-d'))->toBe('2026-02-02')
            ->and($range['to']->format('Y-m-d'))->toBe('2026-02-08');
    });

    it('returns null compare range when mode is none', function () {
        $component = Livewire::test(Statistics::class);
        $component->call('setCompare', 'none');

        expect($component->instance()->compareRange)->toBeNull();
    });

    it('aggregates stats from daily_stats rows', function () {
        DailyStat::create([
            'date' => '2026-02-14',
            'revenue' => 35.00,
            'orders_count' => 2,
            'avg_order_value' => 17.50,
            'new_users' => 5,
            'credits_received' => 1000,
            'credits_spent' => 200,
        ]);

        $component = Livewire::test(Statistics::class);
        $component->call('setPeriod', '7d');

        $stats = $component->instance()->stats;
        expect($stats['revenue'])->toBe(35.0)
            ->and($stats['orders_count'])->toBe(2)
            ->and($stats['new_users'])->toBe(5)
            ->and($stats['credits_received'])->toBe(1000)
            ->and($stats)->toHaveKeys(['revenue_eur', 'revenue_usd', 'orders_eur', 'orders_usd',
                'new_licenses', 'expired_licenses', 'invoice_requested_count']);
    });

    it('calculates delta percentage correctly', function () {
        DailyStat::create([
            'date' => '2026-02-14',
            'revenue' => 100.00,
            'orders_count' => 4,
            'avg_order_value' => 25.00,
            'new_users' => 10,
        ]);

        // Previous period row (7 days before)
        DailyStat::create([
            'date' => '2026-02-07',
            'revenue' => 50.00,
            'orders_count' => 2,
            'avg_order_value' => 25.00,
            'new_users' => 5,
        ]);

        $component = Livewire::test(Statistics::class);
        $component->call('setPeriod', '7d');
        $component->call('setCompare', 'previous');

        $delta = $component->instance()->delta('revenue');
        expect($delta)->toBe(100.0); // 100 vs 50 = +100%
    });

    it('shows empty state when no data', function () {
        Livewire::test(Statistics::class)
            ->assertSee('Geen data beschikbaar');
    });

    it('shows daily rows sorted descending', function () {
        DailyStat::create(['date' => '2026-02-10', 'revenue' => 10.0, 'orders_count' => 1]);
        DailyStat::create(['date' => '2026-02-12', 'revenue' => 20.0, 'orders_count' => 2]);
        DailyStat::create(['date' => '2026-02-14', 'revenue' => 30.0, 'orders_count' => 3]);

        $component = Livewire::test(Statistics::class);
        $component->call('setPeriod', '7d');

        $rows = $component->instance()->dailyRows;
        expect($rows->first()->date->format('Y-m-d'))->toBe('2026-02-14')
            ->and($rows->last()->date->format('Y-m-d'))->toBe('2026-02-10');
    });

    it('aggregates license breakdown from daily_stats', function () {
        DailyStat::create([
            'date' => '2026-02-14',
            'revenue' => 35.00,
            'orders_count' => 1,
            'revenue_by_license' => ['credits-3000' => 35.00],
            'orders_by_license' => ['credits-3000' => 1],
        ]);

        $component = Livewire::test(Statistics::class);
        $component->call('setPeriod', '7d');

        $breakdown = $component->instance()->licenseBreakdown;
        expect($breakdown)->toHaveKey('credits-3000')
            ->and($breakdown['credits-3000']['revenue'])->toBe(35.0)
            ->and($breakdown['credits-3000']['orders'])->toBe(1);
    });

});
