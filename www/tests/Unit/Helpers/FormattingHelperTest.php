<?php

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ===========================================
// format_number
// ===========================================

describe('format_number', function () {

    it('formats with explicit EU user', function () {
        $user = User::factory()->create(['decimal_separator' => ',']);

        expect(format_number(1234.56, 2, $user))->toBe('1.234,56');
    });

    it('formats with explicit US user', function () {
        $user = User::factory()->create(['decimal_separator' => '.']);

        expect(format_number(1234.56, 2, $user))->toBe('1,234.56');
    });

    it('uses auth user when no explicit user', function () {
        $user = User::factory()->create(['decimal_separator' => '.']);
        $this->actingAs($user);

        expect(format_number(9999.99))->toBe('9,999.99');
    });

    it('uses system defaults when no auth and no user', function () {
        // No auth user, no explicit user → DEFAULT_LOCALE decimal_separator = ','
        expect(format_number(1234.56))->toBe('1.234,56');
    });

    it('respects custom decimal places', function () {
        $user = User::factory()->create(['decimal_separator' => ',']);

        expect(format_number(99.5, 0, $user))->toBe('100');
        expect(format_number(99.5, 1, $user))->toBe('99,5');
        expect(format_number(99.5, 3, $user))->toBe('99,500');
    });
});

// ===========================================
// format_currency
// ===========================================

describe('format_currency', function () {

    it('formats EUR with EU user', function () {
        $user = User::factory()->create([
            'decimal_separator' => ',',
            'currency_preference' => 'EUR',
        ]);

        expect(format_currency(49.99, 'EUR', $user))->toBe('€ 49,99');
    });

    it('formats USD with US user', function () {
        $user = User::factory()->create([
            'decimal_separator' => '.',
            'currency_preference' => 'USD',
        ]);

        expect(format_currency(49.99, 'USD', $user))->toBe('$ 49.99');
    });

    it('uses user currency_preference when no explicit currency', function () {
        $user = User::factory()->create([
            'decimal_separator' => '.',
            'currency_preference' => 'GBP',
        ]);

        expect(format_currency(100.00, null, $user))->toBe('£ 100.00');
    });

    it('uses auth user when no explicit user', function () {
        $user = User::factory()->create([
            'decimal_separator' => '.',
            'currency_preference' => 'USD',
        ]);
        $this->actingAs($user);

        expect(format_currency(25.50, 'USD'))->toBe('$ 25.50');
    });
});

// ===========================================
// format_date
// ===========================================

describe('format_date', function () {

    it('formats with explicit NL user', function () {
        $user = User::factory()->create([
            'date_format' => 'd-m-Y',
            'timezone' => 'Europe/Amsterdam',
        ]);

        $date = Carbon::create(2026, 3, 15, 12, 0, 0, 'UTC');

        expect(format_date($date, $user))->toBe('15-03-2026');
    });

    it('formats with explicit US user', function () {
        $user = User::factory()->create([
            'date_format' => 'm/d/Y',
            'timezone' => 'America/New_York',
        ]);

        $date = Carbon::create(2026, 3, 15, 12, 0, 0, 'UTC');

        expect(format_date($date, $user))->toBe('03/15/2026');
    });

    it('uses auth user when no explicit user', function () {
        $user = User::factory()->create([
            'date_format' => 'Y-m-d',
            'timezone' => 'UTC',
        ]);
        $this->actingAs($user);

        $date = Carbon::create(2026, 12, 25, 12, 0, 0, 'UTC');

        expect(format_date($date))->toBe('2026-12-25');
    });

    it('uses system defaults when no auth and no user', function () {
        $date = Carbon::create(2026, 6, 1, 12, 0, 0, 'UTC');

        expect(format_date($date))->toBe('01-06-2026');
    });
});

// ===========================================
// format_datetime
// ===========================================

describe('format_datetime', function () {

    it('formats with explicit EU user', function () {
        $user = User::factory()->create([
            'datetime_format' => 'd-m-Y H:i',
            'timezone' => 'Europe/Amsterdam',
        ]);

        // 14:30 UTC = 15:30 CET
        $date = Carbon::create(2026, 2, 23, 14, 30, 0, 'UTC');

        expect(format_datetime($date, $user))->toBe('23-02-2026 15:30');
    });

    it('formats with explicit US user', function () {
        $user = User::factory()->create([
            'datetime_format' => 'm/d/Y g:i A',
            'timezone' => 'America/New_York',
        ]);

        // 14:30 UTC = 9:30 AM EST
        $date = Carbon::create(2026, 2, 23, 14, 30, 0, 'UTC');

        expect(format_datetime($date, $user))->toBe('02/23/2026 9:30 AM');
    });

    it('uses auth user when no explicit user', function () {
        $user = User::factory()->create([
            'datetime_format' => 'd/m/Y H:i',
            'timezone' => 'UTC',
        ]);
        $this->actingAs($user);

        $date = Carbon::create(2026, 7, 4, 16, 45, 0, 'UTC');

        expect(format_datetime($date))->toBe('04/07/2026 16:45');
    });
});
