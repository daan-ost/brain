<?php

namespace App\Services;

use App\Enums\OrganizationRole;
use App\Models\User;

/**
 * Centralized localization service for user-facing formatting.
 *
 * This service is the single source of truth for all locale-aware formatting:
 * - Date/datetime formatting according to user's date_format/timezone
 * - Number formatting with user's decimal_separator (comma vs dot)
 * - Currency formatting with symbol lookup and number formatting
 *
 * Architecture:
 * - User model stores preferences: timezone, date_format, datetime_format,
 *   decimal_separator, currency_preference, time_format, first_day_of_week
 * - COUNTRY_DEFAULTS provides sensible defaults per country code
 * - DEFAULT_LOCALE is the ultimate fallback (EU-style: comma decimal, d-m-Y dates)
 * - FormattingHelper (app/Helpers/FormattingHelper.php) provides global helper
 *   functions (format_date, format_number, etc.) that wrap this service
 *
 * Usage in Blade templates:
 *   {{ format_date($someDate) }}              — uses auth user's preferences
 *   {{ format_currency($amount, 'EUR') }}     — locale-aware currency display
 *   {{ format_number($value, 2, $user) }}     — explicit user context (e.g. in emails)
 *
 * Usage in services/jobs (no auth context):
 *   $localeService = app(LocaleService::class);
 *   $localeService->formatDate($date, $user);
 *   $localeService->formatCurrency($amount, $user, $order->currency);
 *
 * When reusing in other projects:
 * 1. Copy this service + FormattingHelper + migration for user locale columns
 * 2. Add FormattingHelper to composer.json autoload files array
 * 3. Adjust COUNTRY_DEFAULTS and CURRENCIES for your supported markets
 */
class LocaleService
{
    /**
     * Country-specific locale defaults.
     * Each country has default settings for timezone, currency, date/time formats, etc.
     */
    public const COUNTRY_DEFAULTS = [
        'NL' => [
            'timezone' => 'Europe/Amsterdam',
            'currency' => 'EUR',
            'date_format' => 'd-m-Y',
            'time_format' => '24h',
            'datetime_format' => 'd-m-Y H:i',
            'decimal_separator' => ',',
            'first_day_of_week' => 1,
        ],
        'BE' => [
            'timezone' => 'Europe/Brussels',
            'currency' => 'EUR',
            'date_format' => 'd/m/Y',
            'time_format' => '24h',
            'datetime_format' => 'd/m/Y H:i',
            'decimal_separator' => ',',
            'first_day_of_week' => 1,
        ],
        'DE' => [
            'timezone' => 'Europe/Berlin',
            'currency' => 'EUR',
            'date_format' => 'd.m.Y',
            'time_format' => '24h',
            'datetime_format' => 'd.m.Y H:i',
            'decimal_separator' => ',',
            'first_day_of_week' => 1,
        ],
        'FR' => [
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'date_format' => 'd/m/Y',
            'time_format' => '24h',
            'datetime_format' => 'd/m/Y H:i',
            'decimal_separator' => ',',
            'first_day_of_week' => 1,
        ],
        'GB' => [
            'timezone' => 'Europe/London',
            'currency' => 'GBP',
            'date_format' => 'd/m/Y',
            'time_format' => '24h',
            'datetime_format' => 'd/m/Y H:i',
            'decimal_separator' => '.',
            'first_day_of_week' => 1,
        ],
        'US' => [
            'timezone' => 'America/New_York',
            'currency' => 'USD',
            'date_format' => 'm/d/Y',
            'time_format' => '12h',
            'datetime_format' => 'm/d/Y g:i A',
            'decimal_separator' => '.',
            'first_day_of_week' => 0,
        ],
        'CA' => [
            'timezone' => 'America/Toronto',
            'currency' => 'CAD',
            'date_format' => 'Y-m-d',
            'time_format' => '12h',
            'datetime_format' => 'Y-m-d g:i A',
            'decimal_separator' => '.',
            'first_day_of_week' => 0,
        ],
        'AU' => [
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
            'date_format' => 'd/m/Y',
            'time_format' => '12h',
            'datetime_format' => 'd/m/Y g:i A',
            'decimal_separator' => '.',
            'first_day_of_week' => 1,
        ],
        'AT' => [
            'timezone' => 'Europe/Vienna',
            'currency' => 'EUR',
            'date_format' => 'd.m.Y',
            'time_format' => '24h',
            'datetime_format' => 'd.m.Y H:i',
            'decimal_separator' => ',',
            'first_day_of_week' => 1,
        ],
        'CH' => [
            'timezone' => 'Europe/Zurich',
            'currency' => 'CHF',
            'date_format' => 'd.m.Y',
            'time_format' => '24h',
            'datetime_format' => 'd.m.Y H:i',
            'decimal_separator' => ',',
            'first_day_of_week' => 1,
        ],
        'DK' => [
            'timezone' => 'Europe/Copenhagen',
            'currency' => 'DKK',
            'date_format' => 'd-m-Y',
            'time_format' => '24h',
            'datetime_format' => 'd-m-Y H:i',
            'decimal_separator' => ',',
            'first_day_of_week' => 1,
        ],
        'ES' => [
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
            'date_format' => 'd/m/Y',
            'time_format' => '24h',
            'datetime_format' => 'd/m/Y H:i',
            'decimal_separator' => ',',
            'first_day_of_week' => 1,
        ],
        'FI' => [
            'timezone' => 'Europe/Helsinki',
            'currency' => 'EUR',
            'date_format' => 'd.m.Y',
            'time_format' => '24h',
            'datetime_format' => 'd.m.Y H:i',
            'decimal_separator' => ',',
            'first_day_of_week' => 1,
        ],
        'IT' => [
            'timezone' => 'Europe/Rome',
            'currency' => 'EUR',
            'date_format' => 'd/m/Y',
            'time_format' => '24h',
            'datetime_format' => 'd/m/Y H:i',
            'decimal_separator' => ',',
            'first_day_of_week' => 1,
        ],
        'NO' => [
            'timezone' => 'Europe/Oslo',
            'currency' => 'NOK',
            'date_format' => 'd.m.Y',
            'time_format' => '24h',
            'datetime_format' => 'd.m.Y H:i',
            'decimal_separator' => ',',
            'first_day_of_week' => 1,
        ],
        'PL' => [
            'timezone' => 'Europe/Warsaw',
            'currency' => 'PLN',
            'date_format' => 'd.m.Y',
            'time_format' => '24h',
            'datetime_format' => 'd.m.Y H:i',
            'decimal_separator' => ',',
            'first_day_of_week' => 1,
        ],
        'PT' => [
            'timezone' => 'Europe/Lisbon',
            'currency' => 'EUR',
            'date_format' => 'd/m/Y',
            'time_format' => '24h',
            'datetime_format' => 'd/m/Y H:i',
            'decimal_separator' => ',',
            'first_day_of_week' => 1,
        ],
        'SE' => [
            'timezone' => 'Europe/Stockholm',
            'currency' => 'SEK',
            'date_format' => 'Y-m-d',
            'time_format' => '24h',
            'datetime_format' => 'Y-m-d H:i',
            'decimal_separator' => ',',
            'first_day_of_week' => 1,
        ],
    ];

    /**
     * Default locale settings (fallback for unknown countries).
     */
    public const DEFAULT_LOCALE = [
        'timezone' => 'UTC',
        'currency' => 'EUR',
        'date_format' => 'd-m-Y',
        'time_format' => '24h',
        'datetime_format' => 'd-m-Y H:i',
        'decimal_separator' => ',',
        'first_day_of_week' => 1,
    ];

    /**
     * Available date formats for user selection.
     */
    public const DATE_FORMATS = [
        'd-m-Y' => '31-12-2025',
        'd/m/Y' => '31/12/2025',
        'd.m.Y' => '31.12.2025',
        'm/d/Y' => '12/31/2025',
        'Y-m-d' => '2025-12-31',
    ];

    /**
     * Available time formats.
     */
    public const TIME_FORMATS = [
        '24h' => '14:30',
        '12h' => '2:30 PM',
    ];

    /**
     * Available currencies.
     */
    public const CURRENCIES = [
        'EUR' => ['symbol' => '€', 'name' => 'Euro'],
        'USD' => ['symbol' => '$', 'name' => 'US Dollar'],
        'GBP' => ['symbol' => '£', 'name' => 'British Pound'],
        'CHF' => ['symbol' => 'CHF', 'name' => 'Swiss Franc'],
        'CAD' => ['symbol' => 'C$', 'name' => 'Canadian Dollar'],
        'AUD' => ['symbol' => 'A$', 'name' => 'Australian Dollar'],
        'DKK' => ['symbol' => 'kr', 'name' => 'Danish Krone'],
        'NOK' => ['symbol' => 'kr', 'name' => 'Norwegian Krone'],
        'SEK' => ['symbol' => 'kr', 'name' => 'Swedish Krona'],
        'PLN' => ['symbol' => 'zł', 'name' => 'Polish Zloty'],
    ];

    /**
     * Get the display symbol for a currency code.
     *
     * Returns the symbol from CURRENCIES (e.g. '€' for EUR, '$' for USD).
     * Falls back to the currency code itself for unknown currencies.
     */
    public static function getCurrencySymbol(string $currencyCode): string
    {
        return self::CURRENCIES[$currencyCode]['symbol'] ?? $currencyCode;
    }

    /**
     * Get defaults for a specific country.
     */
    public function getDefaultsForCountry(?string $countryCode): array
    {
        if (! $countryCode) {
            return self::DEFAULT_LOCALE;
        }

        return self::COUNTRY_DEFAULTS[strtoupper($countryCode)] ?? self::DEFAULT_LOCALE;
    }

    /**
     * Apply country defaults to a user (only for fields not manually set).
     */
    public function applyCountryDefaults(User $user, string $countryCode, bool $force = false): void
    {
        $defaults = $this->getDefaultsForCountry($countryCode);

        // Don't apply if user has manually set their locale preferences
        if ($user->locale_manually_set && ! $force) {
            return;
        }

        $updates = [];

        if (! $user->timezone || $force) {
            $updates['timezone'] = $defaults['timezone'];
        }

        if (! $user->currency_preference || $force) {
            $updates['currency_preference'] = $defaults['currency'];
        }

        if (! $user->date_format || $force) {
            $updates['date_format'] = $defaults['date_format'];
        }

        if (! $user->time_format || $force) {
            $updates['time_format'] = $defaults['time_format'];
        }

        if (! $user->datetime_format || $force) {
            $updates['datetime_format'] = $defaults['datetime_format'];
        }

        if (! $user->decimal_separator || $force) {
            $updates['decimal_separator'] = $defaults['decimal_separator'];
        }

        if ($user->first_day_of_week === null || $force) {
            $updates['first_day_of_week'] = $defaults['first_day_of_week'];
        }

        if (! empty($updates)) {
            $user->update($updates);
        }
    }

    /**
     * Get locale settings from browser info (for guests).
     */
    public function getLocaleFromBrowser(?string $browserTimezone, ?string $browserLocale): array
    {
        $locale = self::DEFAULT_LOCALE;

        // Use browser timezone if provided
        if ($browserTimezone && $this->isValidTimezone($browserTimezone)) {
            $locale['timezone'] = $browserTimezone;
        }

        // Determine country from browser locale (e.g., "nl-NL" -> "NL")
        if ($browserLocale) {
            $parts = explode('-', $browserLocale);
            $countryCode = count($parts) > 1 ? strtoupper($parts[1]) : null;

            if ($countryCode && isset(self::COUNTRY_DEFAULTS[$countryCode])) {
                $countryDefaults = self::COUNTRY_DEFAULTS[$countryCode];
                // Merge country defaults but keep browser timezone
                $locale = array_merge($countryDefaults, ['timezone' => $locale['timezone']]);
            }
        }

        return $locale;
    }

    /**
     * Check if a timezone string is valid.
     */
    public function isValidTimezone(string $timezone): bool
    {
        return in_array($timezone, \DateTimeZone::listIdentifiers(), true);
    }

    /**
     * Get all available timezones grouped by region.
     */
    public function getTimezonesByRegion(): array
    {
        $timezones = [];
        $regions = [
            'Africa' => \DateTimeZone::AFRICA,
            'America' => \DateTimeZone::AMERICA,
            'Asia' => \DateTimeZone::ASIA,
            'Atlantic' => \DateTimeZone::ATLANTIC,
            'Australia' => \DateTimeZone::AUSTRALIA,
            'Europe' => \DateTimeZone::EUROPE,
            'Pacific' => \DateTimeZone::PACIFIC,
        ];

        foreach ($regions as $name => $mask) {
            $timezones[$name] = \DateTimeZone::listIdentifiers($mask);
        }

        return $timezones;
    }

    /**
     * Format a date according to user preferences.
     *
     * Converts to the user's timezone and applies their date_format (e.g. 'd-m-Y').
     * When $user is null (guest, queue job without user), uses DEFAULT_LOCALE.
     *
     * @param  \DateTimeInterface  $date  Any date/datetime object (Carbon, DateTime, etc.)
     * @param  User|null  $user  User for preferences, or null for system defaults
     */
    public function formatDate(\DateTimeInterface $date, ?User $user = null): string
    {
        $format = $user?->date_format ?? self::DEFAULT_LOCALE['date_format'];
        $timezone = $user?->timezone ?? self::DEFAULT_LOCALE['timezone'];

        $dateTime = \DateTime::createFromInterface($date);
        $dateTime->setTimezone(new \DateTimeZone($timezone));

        return $dateTime->format($format);
    }

    /**
     * Format a datetime according to user preferences.
     *
     * Same as formatDate but includes time component.
     * Uses user's datetime_format (e.g. 'd-m-Y H:i' or 'm/d/Y g:i A').
     *
     * @param  \DateTimeInterface  $date  Any date/datetime object
     * @param  User|null  $user  User for preferences, or null for system defaults
     */
    public function formatDateTime(\DateTimeInterface $date, ?User $user = null): string
    {
        $format = $user?->datetime_format ?? self::DEFAULT_LOCALE['datetime_format'];
        $timezone = $user?->timezone ?? self::DEFAULT_LOCALE['timezone'];

        $dateTime = \DateTime::createFromInterface($date);
        $dateTime->setTimezone(new \DateTimeZone($timezone));

        return $dateTime->format($format);
    }

    /**
     * Format a number according to user preferences.
     *
     * Applies the user's decimal_separator preference:
     * - Comma decimal (EU): 1.234,56
     * - Dot decimal (US/UK): 1,234.56
     * Thousands separator is automatically the inverse of the decimal separator.
     *
     * @param  float  $number  The number to format
     * @param  User|null  $user  User for preferences, or null for system defaults
     * @param  int  $decimals  Number of decimal places (default: 2)
     */
    public function formatNumber(float $number, ?User $user = null, int $decimals = 2): string
    {
        $decimalSeparator = $user?->decimal_separator ?? self::DEFAULT_LOCALE['decimal_separator'];
        $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';

        return number_format($number, $decimals, $decimalSeparator, $thousandsSeparator);
    }

    /**
     * Format currency according to user preferences.
     *
     * Combines the currency symbol with a locale-formatted number.
     * Symbol is always prepended (e.g. "€ 1.234,56").
     *
     * @param  float  $amount  The amount to format
     * @param  User|null  $user  User for locale preferences (decimal separator)
     * @param  string|null  $currency  ISO 4217 code override (defaults to user preference or EUR)
     */
    public function formatCurrency(float $amount, ?User $user = null, ?string $currency = null): string
    {
        $currencyCode = $currency ?? $user?->currency_preference ?? self::DEFAULT_LOCALE['currency'];
        $symbol = self::getCurrencySymbol($currencyCode);

        $formattedAmount = $this->formatNumber($amount, $user);

        return $symbol.' '.$formattedAmount;
    }

    /**
     * Resolve the primary user associated with an Order.
     *
     * For user orders: returns the payer user directly.
     * For organization orders: returns the earliest-joined admin.
     * Used by invoice generation, email jobs, and formatting helpers
     * to determine locale preferences for a given order.
     *
     * @param  \App\Models\Order  $order  The order to resolve the user for
     * @return User|null  The resolved user, or null if not found
     */
    public static function resolveUserForOrder(\App\Models\Order $order): ?User
    {
        if ($order->payer_type === 'user' && $order->payer_id) {
            return User::find($order->payer_id);
        }

        if ($order->payer_type === 'organization' && $order->payer_id) {
            $org = \App\Models\Organization::find($order->payer_id);

            return $org?->users()
                ->wherePivot('role', OrganizationRole::Owner)
                ->orderBy('organization_user.joined_at', 'asc')
                ->first();
        }

        return null;
    }
}
