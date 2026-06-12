<?php

namespace App\Services;

use App\Models\License;
use App\Models\User;

class PricingCalculatorService
{
    private const TAX_RATE = 21.0; // 21% VAT rate from POC5b spec

    /**
     * Calculate pricing for a license including VAT based on country and VAT ID
     * Note: This calculates the MONTHLY price (not the full billing cycle amount)
     *
     * @param  string  $country  Country code (ISO 3166-1 alpha-2)
     * @param  string|null  $vatId  VAT ID for EU companies (optional)
     * @param  bool  $isCompany  Whether buyer is a company (affects VAT rules)
     * @return array Pricing breakdown with net, tax, gross amounts (monthly)
     */
    public function calculatePricing(License $license, string $country, ?string $vatId = null, bool $isCompany = false): array
    {
        // amount = monthly NET amount (always, regardless of billing_cycle)
        $netAmount = $license->amount;
        $currency = $license->currency;

        // Determine VAT rate based on location and VAT status
        $vatRate = $this->determineVatRate($country, $vatId, $isCompany);

        // Round gross to the nearest whole number so the displayed price matches what is charged.
        // Derive tax from gross - net to guarantee net + tax = gross exactly (no independent rounding).
        if ($vatRate > 0) {
            $grossAmount = (float) round($netAmount * (1 + $vatRate / 100));
            $taxAmount = round($grossAmount - $netAmount, 2);
        } else {
            $taxAmount = 0.0;
            $grossAmount = $netAmount;
        }

        return [
            'net_amount' => $netAmount,
            'tax_amount' => $taxAmount,
            'gross_amount' => $grossAmount,
            'vat_rate' => $vatRate,
            'currency' => $currency,
            'vat_applicable' => $vatRate > 0,
            'vat_reverse_charge' => $this->isEuReverseCharge($country, $vatId, $isCompany),
        ];
    }

    /**
     * Calculate the total billing amount for a license (including billing cycle multiplier)
     * This is what the customer actually pays per billing cycle
     *
     * @param  string  $country  Country code (ISO 3166-1 alpha-2)
     * @param  string|null  $vatId  VAT ID for EU companies (optional)
     * @param  bool  $isCompany  Whether buyer is a company (affects VAT rules)
     * @return array Pricing breakdown for the full billing cycle
     */
    public function calculateBillingAmount(License $license, string $country, ?string $vatId = null, bool $isCompany = false): array
    {
        // Get monthly pricing first
        $monthlyPricing = $this->calculatePricing($license, $country, $vatId, $isCompany);

        // Determine multiplier based on billing cycle
        $billingCycle = $license->billing_cycle ?? 'one_time';
        $multiplier = 1;

        if ($billingCycle === 'yearly') {
            $multiplier = 12;
        } elseif ($billingCycle === 'monthly') {
            $multiplier = 1;
        }

        // Calculate total amounts for the billing cycle
        return [
            'net_amount' => $monthlyPricing['net_amount'] * $multiplier,
            'tax_amount' => $monthlyPricing['tax_amount'] * $multiplier,
            'gross_amount' => $monthlyPricing['gross_amount'] * $multiplier,
            'vat_rate' => $monthlyPricing['vat_rate'],
            'currency' => $monthlyPricing['currency'],
            'vat_applicable' => $monthlyPricing['vat_applicable'],
            'vat_reverse_charge' => $monthlyPricing['vat_reverse_charge'],
            'billing_cycle' => $billingCycle,
            'multiplier' => $multiplier,
            'monthly_net' => $monthlyPricing['net_amount'],
            'monthly_gross' => $monthlyPricing['gross_amount'],
        ];
    }

    /**
     * Determine the appropriate currency based on country
     * EUR for EU + UK/CH/NO, USD for all others
     */
    public function determineCurrency(string $country): string
    {
        $eurCountries = [
            // EU member states
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
            'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
            'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
            // Additional European countries as per spec
            'GB', 'UK', 'CH', 'NO',
        ];

        return in_array(strtoupper($country), $eurCountries) ? 'EUR' : 'USD';
    }

    /**
     * Check if country is in the EU for VAT purposes
     */
    public function isEuCountry(string $country): bool
    {
        $euCountries = [
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
            'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
            'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        ];

        return in_array(strtoupper($country), $euCountries);
    }

    /**
     * Determine VAT rate based on location, VAT ID, and buyer type
     *
     * NL (domestic) → 21% (always, regardless of company status)
     * Other EU + valid VAT ID (company) → 0% (reverse charge)
     * Other EU + no/invalid VAT ID → 21%
     * Non-EU → 0%
     */
    private function determineVatRate(string $country, ?string $vatId, bool $isCompany): float
    {
        // Non-EU: no VAT
        if (! $this->isEuCountry($country)) {
            return 0.0;
        }

        // Netherlands: ALWAYS 21% VAT (domestic supply)
        if (strtoupper($country) === 'NL') {
            return self::TAX_RATE;
        }

        // Other EU countries + company + VAT ID: reverse charge (0%)
        if ($isCompany && ! empty($vatId)) {
            return 0.0;
        }

        // EU individual or company without VAT ID
        return self::TAX_RATE;
    }

    /**
     * Check if this is an EU reverse charge scenario
     * Note: NL (domestic) is never reverse charge
     */
    private function isEuReverseCharge(string $country, ?string $vatId, bool $isCompany): bool
    {
        // Netherlands is never reverse charge (domestic supply)
        if (strtoupper($country) === 'NL') {
            return false;
        }

        return $this->isEuCountry($country) && $isCompany && ! empty($vatId);
    }

    /**
     * Format amount for display with currency symbol.
     *
     * Uses LocaleService for locale-aware number formatting (decimal/thousands separators).
     * Currency symbol is resolved from LocaleService::CURRENCIES.
     *
     * Display rules:
     *   - Net amounts (ex VAT): 2 decimals, trailing .00 stripped (e.g. €34,99 / €35)
     *   - Gross amounts (incl VAT): rounded to whole numbers (e.g. €35)
     *
     * @param  float  $amount  The amount to format
     * @param  string  $currency  ISO 4217 currency code (EUR, USD, etc.)
     * @param  bool  $isNetAmount  True for ex VAT display, false for incl VAT
     * @param  User|null  $user  User for locale preferences (falls back to auth user, then system defaults)
     */
    public function formatAmount(float $amount, string $currency, bool $isNetAmount = false, ?User $user = null): string
    {
        $symbol = LocaleService::getCurrencySymbol($currency);

        // Resolve user: explicit > authenticated > null (system defaults)
        $user = $user ?? auth()->user();
        $localeService = app(LocaleService::class);

        if ($isNetAmount) {
            // Ex VAT: show 2 decimals, strip trailing ,00 / .00 for clean display
            $formatted = $localeService->formatNumber($amount, $user, 2);
            $decimalSep = $user?->decimal_separator ?? LocaleService::DEFAULT_LOCALE['decimal_separator'];
            $formatted = preg_replace('/'.preg_quote($decimalSep, '/').'00$/', '', $formatted);

            return $symbol.$formatted;
        }

        // Incl VAT (calculated): round to whole numbers (34.99 → 35)
        return $symbol.$localeService->formatNumber(round($amount), $user, 0);
    }

    /**
     * Get available licenses for a specific currency and tier
     *
     * @param  string|null  $tier  Optional tier filter
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAvailableLicenses(string $currency, ?string $tier = null)
    {
        $query = License::active()
            ->where('currency', $currency)
            ->orderBy('ordering')
            ->orderBy('amount');

        if ($tier) {
            $query->where('tier', $tier);
        }

        return $query->get();
    }

    /**
     * Get grouped licenses by tier for catalog display
     */
    public function getLicenseCatalog(string $currency): array
    {
        $licenses = $this->getAvailableLicenses($currency);

        $catalog = [
            'free' => [],
            'onetime' => [],
            'premium' => [],
            'enterprise' => [],
            'custom' => [],
        ];

        foreach ($licenses as $license) {
            $tier = $license->tier ?? 'custom';
            if (isset($catalog[$tier])) {
                $catalog[$tier][] = $license;
            }
        }

        return $catalog;
    }
}
