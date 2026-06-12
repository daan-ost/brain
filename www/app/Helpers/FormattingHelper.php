<?php

/**
 * Global formatting helper functions for locale-aware display.
 *
 * These functions wrap LocaleService methods and auto-resolve the current
 * authenticated user for locale preferences. Use these in Blade templates
 * and controllers where auth context is available.
 *
 * For queue jobs, console commands, or email builders where there is no
 * authenticated user, pass the $user parameter explicitly or call
 * LocaleService methods directly.
 *
 * Registered via composer.json autoload.files.
 *
 * @see \App\Services\LocaleService
 */

use App\Models\User;
use App\Services\LocaleService;

if (! function_exists('format_number')) {
    /**
     * Format a number with locale-aware decimal and thousands separators.
     *
     * Examples (EU user): format_number(1234.50) => "1.234,50"
     * Examples (US user): format_number(1234.50) => "1,234.50"
     *
     * @param  float  $number  The number to format
     * @param  int  $decimals  Decimal places (default: 2)
     * @param  User|null  $user  Explicit user, or null to use auth()->user()
     */
    function format_number(float $number, int $decimals = 2, ?User $user = null): string
    {
        $user = $user ?? auth()->user();

        return app(LocaleService::class)->formatNumber($number, $user, $decimals);
    }
}

if (! function_exists('format_currency')) {
    /**
     * Format a currency amount with symbol and locale-aware number formatting.
     *
     * Examples: format_currency(29.99, 'EUR') => "€ 29,99" (EU user)
     *           format_currency(29.99, 'USD') => "$ 29.99" (US user)
     *
     * @param  float  $amount  The amount to format
     * @param  string|null  $currency  ISO 4217 code (defaults to user preference or EUR)
     * @param  User|null  $user  Explicit user, or null to use auth()->user()
     */
    function format_currency(float $amount, ?string $currency = null, ?User $user = null): string
    {
        $user = $user ?? auth()->user();

        return app(LocaleService::class)->formatCurrency($amount, $user, $currency);
    }
}

if (! function_exists('format_date')) {
    /**
     * Format a date according to user's date_format and timezone.
     *
     * Examples: format_date($date) => "23-02-2026" (NL user, d-m-Y)
     *           format_date($date) => "02/23/2026" (US user, m/d/Y)
     *
     * @param  DateTimeInterface  $date  Any date/datetime object (Carbon, DateTime)
     * @param  User|null  $user  Explicit user, or null to use auth()->user()
     */
    function format_date(DateTimeInterface $date, ?User $user = null): string
    {
        $user = $user ?? auth()->user();

        return app(LocaleService::class)->formatDate($date, $user);
    }
}

if (! function_exists('format_datetime')) {
    /**
     * Format a datetime according to user's datetime_format and timezone.
     *
     * Examples: format_datetime($dt) => "23-02-2026 14:30" (NL user)
     *           format_datetime($dt) => "02/23/2026 2:30 PM" (US user)
     *
     * @param  DateTimeInterface  $date  Any date/datetime object (Carbon, DateTime)
     * @param  User|null  $user  Explicit user, or null to use auth()->user()
     */
    function format_datetime(DateTimeInterface $date, ?User $user = null): string
    {
        $user = $user ?? auth()->user();

        return app(LocaleService::class)->formatDateTime($date, $user);
    }
}
