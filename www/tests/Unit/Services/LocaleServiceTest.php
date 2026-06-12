<?php

use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Services\LocaleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new LocaleService;
});

// ===========================================
// formatDate
// ===========================================

describe('formatDate', function () {

    it('formats date with EU user preferences (d-m-Y)', function () {
        $user = User::factory()->create([
            'date_format' => 'd-m-Y',
            'timezone' => 'Europe/Amsterdam',
        ]);

        $date = Carbon::create(2026, 2, 23, 10, 0, 0, 'UTC');

        $result = $this->service->formatDate($date, $user);

        expect($result)->toBe('23-02-2026');
    });

    it('formats date with US user preferences (m/d/Y)', function () {
        $user = User::factory()->create([
            'date_format' => 'm/d/Y',
            'timezone' => 'America/New_York',
        ]);

        $date = Carbon::create(2026, 2, 23, 10, 0, 0, 'UTC');

        $result = $this->service->formatDate($date, $user);

        expect($result)->toBe('02/23/2026');
    });

    it('formats date with ISO format (Y-m-d)', function () {
        $user = User::factory()->create([
            'date_format' => 'Y-m-d',
            'timezone' => 'UTC',
        ]);

        $date = Carbon::create(2026, 12, 31, 0, 0, 0, 'UTC');

        $result = $this->service->formatDate($date, $user);

        expect($result)->toBe('2026-12-31');
    });

    it('uses default locale when user is null', function () {
        $date = Carbon::create(2026, 2, 23, 10, 0, 0, 'UTC');

        $result = $this->service->formatDate($date, null);

        // Default is d-m-Y in UTC
        expect($result)->toBe('23-02-2026');
    });

    it('converts timezone correctly for date near midnight', function () {
        $user = User::factory()->create([
            'date_format' => 'd-m-Y',
            'timezone' => 'Europe/Amsterdam', // UTC+1 in winter
        ]);

        // Feb 23 at 23:30 UTC = Feb 24 at 00:30 CET
        $date = Carbon::create(2026, 2, 23, 23, 30, 0, 'UTC');

        $result = $this->service->formatDate($date, $user);

        expect($result)->toBe('24-02-2026');
    });

    it('handles user with null date_format (falls back to default)', function () {
        $user = User::factory()->create([
            'date_format' => null,
            'timezone' => null,
        ]);

        $date = Carbon::create(2026, 6, 15, 12, 0, 0, 'UTC');

        $result = $this->service->formatDate($date, $user);

        // Falls back to DEFAULT_LOCALE: d-m-Y, UTC
        expect($result)->toBe('15-06-2026');
    });
});

// ===========================================
// formatDateTime
// ===========================================

describe('formatDateTime', function () {

    it('formats datetime with EU 24h format', function () {
        $user = User::factory()->create([
            'datetime_format' => 'd-m-Y H:i',
            'timezone' => 'Europe/Amsterdam',
        ]);

        // Feb 23 14:30 UTC = 15:30 CET
        $date = Carbon::create(2026, 2, 23, 14, 30, 0, 'UTC');

        $result = $this->service->formatDateTime($date, $user);

        expect($result)->toBe('23-02-2026 15:30');
    });

    it('formats datetime with US 12h format', function () {
        $user = User::factory()->create([
            'datetime_format' => 'm/d/Y g:i A',
            'timezone' => 'America/New_York',
        ]);

        // Feb 23 14:30 UTC = 9:30 AM EST
        $date = Carbon::create(2026, 2, 23, 14, 30, 0, 'UTC');

        $result = $this->service->formatDateTime($date, $user);

        expect($result)->toBe('02/23/2026 9:30 AM');
    });

    it('uses default locale when user is null', function () {
        $date = Carbon::create(2026, 2, 23, 14, 30, 0, 'UTC');

        $result = $this->service->formatDateTime($date, null);

        // Default: d-m-Y H:i in UTC
        expect($result)->toBe('23-02-2026 14:30');
    });
});

// ===========================================
// formatNumber
// ===========================================

describe('formatNumber', function () {

    it('formats number with EU decimal separator (comma)', function () {
        $user = User::factory()->create(['decimal_separator' => ',']);

        $result = $this->service->formatNumber(1234.56, $user);

        expect($result)->toBe('1.234,56');
    });

    it('formats number with US decimal separator (dot)', function () {
        $user = User::factory()->create(['decimal_separator' => '.']);

        $result = $this->service->formatNumber(1234.56, $user);

        expect($result)->toBe('1,234.56');
    });

    it('formats number with custom decimal places', function () {
        $user = User::factory()->create(['decimal_separator' => ',']);

        expect($this->service->formatNumber(1234.5, $user, 0))->toBe('1.235');
        expect($this->service->formatNumber(1234.5, $user, 1))->toBe('1.234,5');
        expect($this->service->formatNumber(1234.5, $user, 3))->toBe('1.234,500');
    });

    it('uses default decimal separator (comma) when user is null', function () {
        $result = $this->service->formatNumber(1234.56, null);

        expect($result)->toBe('1.234,56');
    });

    it('formats zero correctly', function () {
        $user = User::factory()->create(['decimal_separator' => ',']);

        expect($this->service->formatNumber(0, $user))->toBe('0,00');
        expect($this->service->formatNumber(0, $user, 0))->toBe('0');
    });

    it('formats large numbers with thousands separators', function () {
        $user = User::factory()->create(['decimal_separator' => '.']);

        $result = $this->service->formatNumber(1234567.89, $user);

        expect($result)->toBe('1,234,567.89');
    });
});

// ===========================================
// formatCurrency
// ===========================================

describe('formatCurrency', function () {

    it('formats EUR with EU user', function () {
        $user = User::factory()->create([
            'decimal_separator' => ',',
            'currency_preference' => 'EUR',
        ]);

        $result = $this->service->formatCurrency(29.99, $user);

        expect($result)->toBe('€ 29,99');
    });

    it('formats USD with US user', function () {
        $user = User::factory()->create([
            'decimal_separator' => '.',
            'currency_preference' => 'USD',
        ]);

        $result = $this->service->formatCurrency(29.99, $user);

        expect($result)->toBe('$ 29.99');
    });

    it('uses explicit currency over user preference', function () {
        $user = User::factory()->create([
            'decimal_separator' => '.',
            'currency_preference' => 'EUR',
        ]);

        $result = $this->service->formatCurrency(29.99, $user, 'GBP');

        expect($result)->toBe('£ 29.99');
    });

    it('uses user currency_preference when no explicit currency', function () {
        $user = User::factory()->create([
            'decimal_separator' => ',',
            'currency_preference' => 'CHF',
        ]);

        $result = $this->service->formatCurrency(100.00, $user);

        expect($result)->toBe('CHF 100,00');
    });

    it('falls back to EUR when user is null and no currency specified', function () {
        $result = $this->service->formatCurrency(50.00, null);

        // Default: EUR with comma decimal
        expect($result)->toBe('€ 50,00');
    });

    it('formats large currency amounts correctly', function () {
        $user = User::factory()->create(['decimal_separator' => ',']);

        $result = $this->service->formatCurrency(12345.67, $user, 'EUR');

        expect($result)->toBe('€ 12.345,67');
    });
});

// ===========================================
// getCurrencySymbol
// ===========================================

describe('getCurrencySymbol', function () {

    it('returns correct symbols for known currencies', function () {
        expect(LocaleService::getCurrencySymbol('EUR'))->toBe('€');
        expect(LocaleService::getCurrencySymbol('USD'))->toBe('$');
        expect(LocaleService::getCurrencySymbol('GBP'))->toBe('£');
        expect(LocaleService::getCurrencySymbol('CHF'))->toBe('CHF');
        expect(LocaleService::getCurrencySymbol('CAD'))->toBe('C$');
        expect(LocaleService::getCurrencySymbol('AUD'))->toBe('A$');
        expect(LocaleService::getCurrencySymbol('DKK'))->toBe('kr');
        expect(LocaleService::getCurrencySymbol('PLN'))->toBe('zł');
    });

    it('returns currency code for unknown currencies', function () {
        expect(LocaleService::getCurrencySymbol('JPY'))->toBe('JPY');
        expect(LocaleService::getCurrencySymbol('BRL'))->toBe('BRL');
        expect(LocaleService::getCurrencySymbol('UNKNOWN'))->toBe('UNKNOWN');
    });
});

// ===========================================
// getDefaultsForCountry
// ===========================================

describe('getDefaultsForCountry', function () {

    it('returns NL defaults', function () {
        $defaults = $this->service->getDefaultsForCountry('NL');

        expect($defaults['timezone'])->toBe('Europe/Amsterdam');
        expect($defaults['currency'])->toBe('EUR');
        expect($defaults['date_format'])->toBe('d-m-Y');
        expect($defaults['decimal_separator'])->toBe(',');
        expect($defaults['first_day_of_week'])->toBe(1);
    });

    it('returns US defaults', function () {
        $defaults = $this->service->getDefaultsForCountry('US');

        expect($defaults['timezone'])->toBe('America/New_York');
        expect($defaults['currency'])->toBe('USD');
        expect($defaults['date_format'])->toBe('m/d/Y');
        expect($defaults['decimal_separator'])->toBe('.');
        expect($defaults['time_format'])->toBe('12h');
        expect($defaults['first_day_of_week'])->toBe(0);
    });

    it('returns default locale for unknown country', function () {
        $defaults = $this->service->getDefaultsForCountry('XX');

        expect($defaults)->toBe(LocaleService::DEFAULT_LOCALE);
    });

    it('returns default locale for null country', function () {
        $defaults = $this->service->getDefaultsForCountry(null);

        expect($defaults)->toBe(LocaleService::DEFAULT_LOCALE);
    });

    it('handles lowercase country codes', function () {
        $defaults = $this->service->getDefaultsForCountry('nl');

        expect($defaults['timezone'])->toBe('Europe/Amsterdam');
    });
});

// ===========================================
// applyCountryDefaults
// ===========================================

describe('applyCountryDefaults', function () {

    it('applies all defaults with force flag', function () {
        $user = User::factory()->create([
            'timezone' => 'UTC',
            'currency_preference' => 'EUR',
            'date_format' => 'd-m-Y',
            'time_format' => '24h',
            'datetime_format' => 'd-m-Y H:i',
            'decimal_separator' => ',',
            'first_day_of_week' => 1,
            'locale_manually_set' => false,
        ]);

        $this->service->applyCountryDefaults($user, 'US', force: true);
        $user->refresh();

        expect($user->timezone)->toBe('America/New_York');
        expect($user->currency_preference)->toBe('USD');
        expect($user->date_format)->toBe('m/d/Y');
        expect($user->time_format)->toBe('12h');
        expect($user->decimal_separator)->toBe('.');
        expect($user->first_day_of_week)->toBe(0);
    });

    it('does not overwrite existing non-empty values without force', function () {
        $user = User::factory()->create([
            'timezone' => 'Europe/Amsterdam',
            'currency_preference' => 'EUR',
            'date_format' => 'd-m-Y',
            'time_format' => '24h',
            'datetime_format' => 'd-m-Y H:i',
            'decimal_separator' => ',',
            'first_day_of_week' => 1,
            'locale_manually_set' => false,
        ]);

        $this->service->applyCountryDefaults($user, 'US');
        $user->refresh();

        // Existing non-empty values should remain unchanged
        expect($user->timezone)->toBe('Europe/Amsterdam');
        expect($user->currency_preference)->toBe('EUR');
        expect($user->date_format)->toBe('d-m-Y');
        expect($user->decimal_separator)->toBe(',');
    });

    it('does not override when locale_manually_set is true', function () {
        $user = User::factory()->create([
            'timezone' => 'Europe/Amsterdam',
            'decimal_separator' => ',',
            'locale_manually_set' => true,
        ]);

        $this->service->applyCountryDefaults($user, 'US');
        $user->refresh();

        // Should remain unchanged
        expect($user->timezone)->toBe('Europe/Amsterdam');
        expect($user->decimal_separator)->toBe(',');
    });

    it('overrides when force is true even with locale_manually_set', function () {
        $user = User::factory()->create([
            'timezone' => 'Europe/Amsterdam',
            'decimal_separator' => ',',
            'locale_manually_set' => true,
        ]);

        $this->service->applyCountryDefaults($user, 'US', force: true);
        $user->refresh();

        expect($user->timezone)->toBe('America/New_York');
        expect($user->decimal_separator)->toBe('.');
    });
});

// ===========================================
// getLocaleFromBrowser
// ===========================================

describe('getLocaleFromBrowser', function () {

    it('uses browser timezone when valid', function () {
        $locale = $this->service->getLocaleFromBrowser('America/Chicago', null);

        expect($locale['timezone'])->toBe('America/Chicago');
    });

    it('ignores invalid browser timezone', function () {
        $locale = $this->service->getLocaleFromBrowser('Invalid/Timezone', null);

        expect($locale['timezone'])->toBe('UTC');
    });

    it('applies country defaults from browser locale', function () {
        $locale = $this->service->getLocaleFromBrowser(null, 'nl-NL');

        expect($locale['currency'])->toBe('EUR');
        expect($locale['date_format'])->toBe('d-m-Y');
        expect($locale['decimal_separator'])->toBe(',');
    });

    it('keeps browser timezone when merging country defaults', function () {
        $locale = $this->service->getLocaleFromBrowser('America/Chicago', 'nl-NL');

        // Browser timezone should be preserved
        expect($locale['timezone'])->toBe('America/Chicago');
        // Country defaults should be applied
        expect($locale['currency'])->toBe('EUR');
        expect($locale['date_format'])->toBe('d-m-Y');
    });

    it('returns defaults for unknown browser locale', function () {
        $locale = $this->service->getLocaleFromBrowser(null, 'xx-XX');

        expect($locale)->toBe(LocaleService::DEFAULT_LOCALE);
    });
});

// ===========================================
// isValidTimezone
// ===========================================

describe('isValidTimezone', function () {

    it('accepts valid timezones', function () {
        expect($this->service->isValidTimezone('UTC'))->toBeTrue();
        expect($this->service->isValidTimezone('Europe/Amsterdam'))->toBeTrue();
        expect($this->service->isValidTimezone('America/New_York'))->toBeTrue();
    });

    it('rejects invalid timezones', function () {
        expect($this->service->isValidTimezone('Invalid/Timezone'))->toBeFalse();
        expect($this->service->isValidTimezone(''))->toBeFalse();
        expect($this->service->isValidTimezone('CEST'))->toBeFalse();
    });
});

// ===========================================
// resolveUserForOrder
// ===========================================

describe('resolveUserForOrder', function () {

    it('returns user for user-type orders', function () {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
        ]);

        $resolved = LocaleService::resolveUserForOrder($order);

        expect($resolved)->not->toBeNull();
        expect($resolved->id)->toBe($user->id);
    });

    it('returns earliest admin for organization-type orders', function () {
        $org = Organization::factory()->create();
        $admin1 = User::factory()->create();
        $admin2 = User::factory()->create();

        // Admin1 joined first
        $org->users()->attach($admin1->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()->subDays(10)]);
        $org->users()->attach($admin2->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()->subDays(5)]);

        $order = Order::factory()->create([
            'payer_type' => 'organization',
            'payer_id' => $org->id,
        ]);

        $resolved = LocaleService::resolveUserForOrder($order);

        expect($resolved)->not->toBeNull();
        expect($resolved->id)->toBe($admin1->id);
    });

    it('skips member role and returns admin for org orders', function () {
        $org = Organization::factory()->create();
        $member = User::factory()->create();
        $admin = User::factory()->create();

        $org->users()->attach($member->id, ['role' => \App\Enums\OrganizationRole::Editor->value, 'joined_at' => now()->subDays(10)]);
        $org->users()->attach($admin->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()->subDays(5)]);

        $order = Order::factory()->create([
            'payer_type' => 'organization',
            'payer_id' => $org->id,
        ]);

        $resolved = LocaleService::resolveUserForOrder($order);

        expect($resolved->id)->toBe($admin->id);
    });

    it('returns null when user payer does not exist', function () {
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => 999999,
        ]);

        $resolved = LocaleService::resolveUserForOrder($order);

        expect($resolved)->toBeNull();
    });

    it('returns null when payer_id is null', function () {
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => null,
        ]);

        $resolved = LocaleService::resolveUserForOrder($order);

        expect($resolved)->toBeNull();
    });
});
