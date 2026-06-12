<?php

declare(strict_types=1);

use App\Models\DailyStat;
use App\Services\DailyStatsService;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-02-15'));
});

afterEach(fn () => Carbon::setTestNow());

// ================================================================
// resolvePeriod
// ================================================================

describe('DailyStatsService::resolvePeriod', function () {

    it('resolves 7d preset to last 7 days inclusive', function () {
        $range = DailyStatsService::resolvePeriod('7d');

        expect($range['from']->format('Y-m-d'))->toBe('2026-02-09')
            ->and($range['to']->format('Y-m-d'))->toBe('2026-02-15');
    });

    it('resolves 30d preset to last 30 days inclusive', function () {
        $range = DailyStatsService::resolvePeriod('30d');

        expect($range['from']->format('Y-m-d'))->toBe('2026-01-17')
            ->and($range['to']->format('Y-m-d'))->toBe('2026-02-15');
    });

    it('resolves 90d preset', function () {
        $range = DailyStatsService::resolvePeriod('90d');

        expect($range['from']->format('Y-m-d'))->toBe('2025-11-18')
            ->and($range['to']->format('Y-m-d'))->toBe('2026-02-15');
    });

    it('resolves mtd preset to start of current month', function () {
        $range = DailyStatsService::resolvePeriod('mtd');

        expect($range['from']->format('Y-m-d'))->toBe('2026-02-01')
            ->and($range['to']->format('Y-m-d'))->toBe('2026-02-15');
    });

    it('resolves prev_month preset', function () {
        $range = DailyStatsService::resolvePeriod('prev_month');

        expect($range['from']->format('Y-m-d'))->toBe('2026-01-01')
            ->and($range['to']->format('Y-m-d'))->toBe('2026-01-31');
    });

    it('resolves ytd preset to start of current year', function () {
        $range = DailyStatsService::resolvePeriod('ytd');

        expect($range['from']->format('Y-m-d'))->toBe('2026-01-01')
            ->and($range['to']->format('Y-m-d'))->toBe('2026-02-15');
    });

    it('resolves 12m preset to start of month 12 months ago', function () {
        $range = DailyStatsService::resolvePeriod('12m');

        expect($range['from']->format('Y-m-d'))->toBe('2025-03-01')
            ->and($range['to']->format('Y-m-d'))->toBe('2026-02-15');
    });

    it('resolves custom preset with given dates', function () {
        $range = DailyStatsService::resolvePeriod('custom', '2026-01-10', '2026-01-20');

        expect($range['from']->format('Y-m-d'))->toBe('2026-01-10')
            ->and($range['to']->format('Y-m-d'))->toBe('2026-01-20');
    });

    it('falls back to 30d for unknown preset', function () {
        $range = DailyStatsService::resolvePeriod('unknown_preset');

        expect($range['from']->format('Y-m-d'))->toBe('2026-01-17')
            ->and($range['to']->format('Y-m-d'))->toBe('2026-02-15');
    });

});

// ================================================================
// resolveComparePeriod
// ================================================================

describe('DailyStatsService::resolveComparePeriod', function () {

    it('returns null for mode none', function () {
        $from = Carbon::parse('2026-02-09');
        $to = Carbon::parse('2026-02-15');

        expect(DailyStatsService::resolveComparePeriod('none', $from, $to))->toBeNull();
    });

    it('returns the previous equal-length period for mode previous', function () {
        $from = Carbon::parse('2026-02-09');
        $to = Carbon::parse('2026-02-15'); // 6 days span

        $range = DailyStatsService::resolveComparePeriod('previous', $from, $to);

        expect($range['from']->format('Y-m-d'))->toBe('2026-02-02')
            ->and($range['to']->format('Y-m-d'))->toBe('2026-02-08');
    });

    it('returns year-ago period for mode year', function () {
        $from = Carbon::parse('2026-02-09');
        $to = Carbon::parse('2026-02-15');

        $range = DailyStatsService::resolveComparePeriod('year', $from, $to);

        expect($range['from']->format('Y-m-d'))->toBe('2025-02-09')
            ->and($range['to']->format('Y-m-d'))->toBe('2025-02-15');
    });

    it('returns null for unknown compare mode', function () {
        $from = Carbon::parse('2026-02-09');
        $to = Carbon::parse('2026-02-15');

        expect(DailyStatsService::resolveComparePeriod('unknown', $from, $to))->toBeNull();
    });

});

// ================================================================
// aggregate
// ================================================================

describe('DailyStatsService->aggregate', function () {

    it('sums revenue, orders and users across rows in range', function () {
        DailyStat::create(['date' => '2026-02-10', 'revenue' => 100.00, 'orders_count' => 3, 'new_users' => 5, 'credits_received' => 500]);
        DailyStat::create(['date' => '2026-02-11', 'revenue' => 200.00, 'orders_count' => 2, 'new_users' => 3, 'credits_received' => 300]);

        $stats = app(DailyStatsService::class)->aggregate(
            Carbon::parse('2026-02-10'),
            Carbon::parse('2026-02-11')
        );

        expect($stats['revenue'])->toBe(300.0)
            ->and($stats['orders_count'])->toBe(5)
            ->and($stats['new_users'])->toBe(8)
            ->and($stats['credits_received'])->toBe(800)
            ->and($stats['days'])->toBe(2);
    });

    it('calculates avg_order_value from period totals not per-row averages', function () {
        DailyStat::create(['date' => '2026-02-10', 'revenue' => 100.00, 'orders_count' => 4]);
        DailyStat::create(['date' => '2026-02-11', 'revenue' => 200.00, 'orders_count' => 4]);

        $stats = app(DailyStatsService::class)->aggregate(
            Carbon::parse('2026-02-10'),
            Carbon::parse('2026-02-11')
        );

        // 300 / 8 = 37.50
        expect($stats['avg_order_value'])->toBe(37.50);
    });

    it('returns zero avg_order_value when no orders', function () {
        DailyStat::create(['date' => '2026-02-10', 'revenue' => 0.0, 'orders_count' => 0]);

        $stats = app(DailyStatsService::class)->aggregate(
            Carbon::parse('2026-02-10'),
            Carbon::parse('2026-02-10')
        );

        expect($stats['avg_order_value'])->toBe(0.0);
    });

    it('returns zeroes when no rows in range', function () {
        $stats = app(DailyStatsService::class)->aggregate(
            Carbon::parse('2026-02-10'),
            Carbon::parse('2026-02-11')
        );

        expect($stats['revenue'])->toBe(0.0)
            ->and($stats['orders_count'])->toBe(0)
            ->and($stats['days'])->toBe(0);
    });

    it('calculates checkout_conversion percentage', function () {
        DailyStat::create(['date' => '2026-02-10', 'checkout_started' => 10, 'credits_purchased_events' => 3]);

        $stats = app(DailyStatsService::class)->aggregate(
            Carbon::parse('2026-02-10'),
            Carbon::parse('2026-02-10')
        );

        expect($stats['checkout_conversion'])->toBe(30.0);
    });

    it('returns zero checkout_conversion when no checkouts started', function () {
        DailyStat::create(['date' => '2026-02-10', 'checkout_started' => 0, 'credits_purchased_events' => 0]);

        $stats = app(DailyStatsService::class)->aggregate(
            Carbon::parse('2026-02-10'),
            Carbon::parse('2026-02-10')
        );

        expect($stats['checkout_conversion'])->toBe(0.0);
    });

    it('excludes rows outside the date range', function () {
        DailyStat::create(['date' => '2026-02-08', 'revenue' => 999.00, 'orders_count' => 99]);
        DailyStat::create(['date' => '2026-02-10', 'revenue' => 50.00, 'orders_count' => 1]);
        DailyStat::create(['date' => '2026-02-16', 'revenue' => 999.00, 'orders_count' => 99]);

        $stats = app(DailyStatsService::class)->aggregate(
            Carbon::parse('2026-02-10'),
            Carbon::parse('2026-02-10')
        );

        expect($stats['revenue'])->toBe(50.0)
            ->and($stats['orders_count'])->toBe(1);
    });

});

// ================================================================
// revenueByLicense
// ================================================================

describe('DailyStatsService->revenueByLicense', function () {

    it('aggregates revenue and orders by license slug across rows', function () {
        DailyStat::create([
            'date'               => '2026-02-10',
            'revenue'            => 70.00,
            'orders_count'       => 3,
            'revenue_by_license' => ['credits-3000' => 35.00, 'credits-1000' => 35.00],
            'orders_by_license'  => ['credits-3000' => 1,     'credits-1000' => 2],
        ]);
        DailyStat::create([
            'date'               => '2026-02-11',
            'revenue'            => 35.00,
            'orders_count'       => 1,
            'revenue_by_license' => ['credits-3000' => 35.00],
            'orders_by_license'  => ['credits-3000' => 1],
        ]);

        $breakdown = app(DailyStatsService::class)->revenueByLicense(
            Carbon::parse('2026-02-10'),
            Carbon::parse('2026-02-11')
        );

        expect($breakdown)->toHaveKeys(['credits-3000', 'credits-1000'])
            ->and($breakdown['credits-3000']['revenue'])->toBe(70.0)
            ->and($breakdown['credits-3000']['orders'])->toBe(2)
            ->and($breakdown['credits-1000']['revenue'])->toBe(35.0)
            ->and($breakdown['credits-1000']['orders'])->toBe(2);
    });

    it('sorts by revenue descending', function () {
        DailyStat::create([
            'date'               => '2026-02-10',
            'revenue'            => 150.00,
            'orders_count'       => 3,
            'revenue_by_license' => ['small' => 10.00, 'big' => 140.00],
            'orders_by_license'  => ['small' => 1,     'big' => 2],
        ]);

        $breakdown = app(DailyStatsService::class)->revenueByLicense(
            Carbon::parse('2026-02-10'),
            Carbon::parse('2026-02-10')
        );

        expect(array_key_first($breakdown))->toBe('big');
    });

    it('returns empty array when no license breakdown data exists', function () {
        DailyStat::create(['date' => '2026-02-10', 'revenue' => 50.00, 'orders_count' => 1]);

        $breakdown = app(DailyStatsService::class)->revenueByLicense(
            Carbon::parse('2026-02-10'),
            Carbon::parse('2026-02-10')
        );

        expect($breakdown)->toBeEmpty();
    });

});

// ================================================================
// ordersByTier
// ================================================================

describe('DailyStatsService->ordersByTier', function () {

    it('aggregates count and revenue per tier across rows', function () {
        DailyStat::create([
            'date'          => '2026-02-10',
            'revenue'       => 100.00,
            'orders_count'  => 3,
            'orders_by_tier' => ['onetime' => ['count' => 2, 'revenue' => 70.00], 'free' => ['count' => 1, 'revenue' => 0.00]],
        ]);
        DailyStat::create([
            'date'          => '2026-02-11',
            'revenue'       => 35.00,
            'orders_count'  => 1,
            'orders_by_tier' => ['onetime' => ['count' => 1, 'revenue' => 35.00]],
        ]);

        $tiers = app(DailyStatsService::class)->ordersByTier(
            Carbon::parse('2026-02-10'),
            Carbon::parse('2026-02-11')
        );

        expect($tiers)->toHaveKeys(['onetime', 'free'])
            ->and($tiers['onetime']['count'])->toBe(3)
            ->and($tiers['onetime']['revenue'])->toBe(105.0)
            ->and($tiers['free']['count'])->toBe(1)
            ->and($tiers['free']['revenue'])->toBe(0.0);
    });

    it('returns empty array when no tier data', function () {
        DailyStat::create(['date' => '2026-02-10', 'revenue' => 0.0, 'orders_count' => 0]);

        $tiers = app(DailyStatsService::class)->ordersByTier(
            Carbon::parse('2026-02-10'),
            Carbon::parse('2026-02-10')
        );

        expect($tiers)->toBeEmpty();
    });

});
