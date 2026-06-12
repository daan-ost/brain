<?php

use App\Models\User;
use App\Services\LocaleService;
use App\Services\PricingCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new PricingCalculatorService;
});

// ===========================================
// formatAmount locale-aware behavior
// ===========================================

describe('formatAmount with locale', function () {

    it('formats net EUR amount with EU user (comma decimal)', function () {
        $user = User::factory()->create(['decimal_separator' => ',']);

        $result = $this->service->formatAmount(34.99, 'EUR', true, $user);

        expect($result)->toBe('€34,99');
    });

    it('formats net USD amount with US user (dot decimal)', function () {
        $user = User::factory()->create(['decimal_separator' => '.']);

        $result = $this->service->formatAmount(34.99, 'USD', true, $user);

        expect($result)->toBe('$34.99');
    });

    it('strips trailing ,00 for EU user on net amounts', function () {
        $user = User::factory()->create(['decimal_separator' => ',']);

        $result = $this->service->formatAmount(35.00, 'EUR', true, $user);

        expect($result)->toBe('€35');
    });

    it('strips trailing .00 for US user on net amounts', function () {
        $user = User::factory()->create(['decimal_separator' => '.']);

        $result = $this->service->formatAmount(35.00, 'USD', true, $user);

        expect($result)->toBe('$35');
    });

    it('does not strip non-zero decimals for EU user', function () {
        $user = User::factory()->create(['decimal_separator' => ',']);

        $result = $this->service->formatAmount(34.50, 'EUR', true, $user);

        expect($result)->toBe('€34,50');
    });

    it('formats gross amount as whole number for EU user', function () {
        $user = User::factory()->create(['decimal_separator' => ',']);

        $result = $this->service->formatAmount(42.35, 'EUR', false, $user);

        expect($result)->toBe('€42');
    });

    it('formats gross amount as whole number for US user', function () {
        $user = User::factory()->create(['decimal_separator' => '.']);

        $result = $this->service->formatAmount(42.35, 'USD', false, $user);

        expect($result)->toBe('$42');
    });

    it('uses correct symbol for GBP', function () {
        $user = User::factory()->create(['decimal_separator' => '.']);

        $result = $this->service->formatAmount(100.00, 'GBP', true, $user);

        expect($result)->toBe('£100');
    });

    it('falls back to currency code for unknown currency', function () {
        $user = User::factory()->create(['decimal_separator' => '.']);

        $result = $this->service->formatAmount(50.00, 'JPY', true, $user);

        expect($result)->toBe('JPY50');
    });

    it('uses auth user when no explicit user', function () {
        $user = User::factory()->create(['decimal_separator' => '.']);
        $this->actingAs($user);

        $result = $this->service->formatAmount(29.99, 'EUR', true);

        expect($result)->toBe('€29.99');
    });

    it('uses system defaults when no user context', function () {
        // No auth user → defaults to comma decimal
        $result = $this->service->formatAmount(29.99, 'EUR', true);

        expect($result)->toBe('€29,99');
    });

    it('formats large net amounts with thousands separator', function () {
        $user = User::factory()->create(['decimal_separator' => ',']);

        $result = $this->service->formatAmount(1234.56, 'EUR', true, $user);

        expect($result)->toBe('€1.234,56');
    });

    it('formats large gross amounts with thousands separator', function () {
        $user = User::factory()->create(['decimal_separator' => '.']);

        $result = $this->service->formatAmount(12345.67, 'USD', false, $user);

        expect($result)->toBe('$12,346');
    });
});
