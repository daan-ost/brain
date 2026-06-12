<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CountryContextResolver
{
    private const VAT_VALIDATION_TTL = 30 * 24 * 60 * 60; // 30 days in seconds

    private const IP_LOOKUP_COOLDOWN = 5 * 60; // 5 minutes between IP lookups for same payer

    public function __construct(
        private IPRegistryService $ipRegistry,
        private VIESValidationService $viesValidator,
        private PricingCalculatorService $pricingCalculator
    ) {}

    /**
     * Resolve complete country/VAT context for a payer
     *
     * @param  string  $payerType  'user' or 'organization'
     * @return array Context with country, currency, vat info
     */
    public function resolveContext(string $payerType, int $payerId): array
    {
        $payer = $this->getPayer($payerType, $payerId);

        if (! $payer) {
            return $this->getDefaultContext();
        }

        // Resolve country
        $country = $this->resolveCountry($payer, $payerType);

        // Resolve currency
        $currency = $this->resolveCurrency($payer, $country);

        // Resolve VAT context
        $vatContext = $this->resolveVatContext($payer, $country);

        $context = [
            'country' => $country,
            'currency' => $currency,
            'vat_number' => $vatContext['vat_number'],
            'vat_valid' => $vatContext['vat_valid'],
            'vat_validated_at' => $vatContext['vat_validated_at'],
            'payer_type' => $payerType,
            'payer_id' => $payerId,
            'payer' => $payer,
        ];

        Log::info('Country context resolved', [
            'payer_type' => $payerType,
            'payer_id' => $payerId,
            'country' => $country,
            'currency' => $currency,
            'has_vat_number' => ! empty($vatContext['vat_number']),
            'vat_valid' => $vatContext['vat_valid'],
        ]);

        return $context;
    }

    /**
     * Update and persist country/VAT data for a payer
     *
     * @param  array  $data  Updates to apply
     * @return array Updated context
     */
    public function updateContext(string $payerType, int $payerId, array $data): array
    {
        $payer = $this->getPayer($payerType, $payerId);

        if (! $payer) {
            return $this->getDefaultContext();
        }

        $updates = [];
        $revalidateVat = false;

        // Update country if provided
        if (isset($data['country'])) {
            $country = strtoupper($data['country']);
            if ($payer->billing_country_code !== $country) {
                $updates['billing_country_code'] = $country;

                // Update currency preference based on new country
                $newCurrency = $this->pricingCalculator->determineCurrency($country);
                if ($payer->currency_preference !== $newCurrency) {
                    $updates['currency_preference'] = $newCurrency;
                }
            }
        }

        // Update VAT number if provided
        if (isset($data['vat_number'])) {
            $vatNumber = strtoupper(trim($data['vat_number']));
            if ($payer->vat_number !== $vatNumber) {
                $updates['vat_number'] = $vatNumber;
                // Clear validation timestamp to trigger re-validation
                $updates['vat_validated_at'] = null;
                $revalidateVat = ! empty($vatNumber);
            }
        }

        // Apply updates
        if (! empty($updates)) {
            $payer->update($updates);
            Log::info('Payer context updated', [
                'payer_type' => $payerType,
                'payer_id' => $payerId,
                'updates' => $updates,
            ]);
        }

        // Re-validate VAT if needed
        if ($revalidateVat) {
            $this->validateAndPersistVat($payer);
        }

        // Return updated context
        return $this->resolveContext($payerType, $payerId);
    }

    /**
     * Resolve country for a payer (with IP lookup if needed)
     */
    private function resolveCountry($payer, string $payerType): string
    {
        // 1. Use billing country if set
        if ($payer->billing_country_code) {
            return $payer->billing_country_code;
        }

        // 2. Use IP-detected country if available
        if ($payer->ipregistry_country_code) {
            return $payer->ipregistry_country_code;
        }

        // 3. Perform IP lookup (first time only, with cooldown)
        return $this->performIpLookup($payer, $payerType);
    }

    /**
     * Resolve currency for a payer (persist preference if not set)
     */
    private function resolveCurrency($payer, string $country): string
    {
        // Use existing preference if set
        if ($payer->currency_preference) {
            return $payer->currency_preference;
        }

        // Determine and persist currency preference
        $currency = $this->pricingCalculator->determineCurrency($country);

        $payer->update(['currency_preference' => $currency]);

        Log::info('Currency preference persisted', [
            'payer_type' => get_class($payer),
            'payer_id' => $payer->id,
            'country' => $country,
            'currency' => $currency,
        ]);

        return $currency;
    }

    /**
     * Resolve VAT context with validation if needed
     */
    private function resolveVatContext($payer, string $country): array
    {
        $vatNumber = $payer->vat_number ?? null;
        $vatValidatedAt = $payer->vat_validated_at;
        $vatValid = false;

        if ($vatNumber && $this->pricingCalculator->isEuCountry($country)) {
            // Check if validation is needed
            $needsValidation = is_null($vatValidatedAt) ||
                              now()->diffInSeconds($vatValidatedAt) > self::VAT_VALIDATION_TTL;

            if ($needsValidation) {
                $vatValid = $this->validateAndPersistVat($payer, $vatNumber);
                $vatValidatedAt = $payer->fresh()->vat_validated_at;
            } else {
                // Use existing validation (assume valid if timestamp exists)
                $vatValid = ! is_null($vatValidatedAt);
            }
        }

        return [
            'vat_number' => $vatNumber,
            'vat_valid' => $vatValid,
            'vat_validated_at' => $vatValidatedAt,
        ];
    }

    /**
     * Perform IP lookup with cooldown protection
     */
    private function performIpLookup($payer, string $payerType): string
    {
        $cacheKey = "ip_lookup_cooldown:{$payerType}:{$payer->id}";

        if (Cache::has($cacheKey)) {
            Log::info('IP lookup skipped due to cooldown', [
                'payer_type' => $payerType,
                'payer_id' => $payer->id,
            ]);

            return 'NL'; // Default fallback
        }

        try {
            $countryInfo = $this->ipRegistry->getCountryFromIP();
            $country = $countryInfo['code'] ?? 'NL';

            // Persist the detected country
            $payer->update([
                'ipregistry_country_code' => $country,
                'ipregistry_checked_at' => now(),
            ]);

            // Set cooldown
            Cache::put($cacheKey, true, self::IP_LOOKUP_COOLDOWN);

            Log::info('IP lookup performed and cached', [
                'payer_type' => $payerType,
                'payer_id' => $payer->id,
                'detected_country' => $country,
            ]);

            return $country;

        } catch (\Exception $e) {
            Log::warning('IP lookup failed', [
                'payer_type' => $payerType,
                'payer_id' => $payer->id,
                'error' => $e->getMessage(),
            ]);

            // Set cooldown even on failure
            Cache::put($cacheKey, true, self::IP_LOOKUP_COOLDOWN);

            return 'NL'; // Default fallback
        }
    }

    /**
     * Validate VAT number and persist result
     */
    private function validateAndPersistVat($payer, ?string $vatNumber = null): bool
    {
        $vatNumber = $vatNumber ?? $payer->vat_number;

        if (! $vatNumber) {
            return false;
        }

        try {
            $validation = $this->viesValidator->validateVatId($vatNumber);
            $isValid = $validation['valid'] ?? false;

            if ($isValid) {
                $payer->update(['vat_validated_at' => now()]);

                Log::info('VAT number validated and cached', [
                    'payer_type' => get_class($payer),
                    'payer_id' => $payer->id,
                    'vat_number' => $vatNumber,
                ]);
            } else {
                Log::warning('VAT validation failed', [
                    'payer_type' => get_class($payer),
                    'payer_id' => $payer->id,
                    'vat_number' => $vatNumber,
                    'error' => $validation['error'] ?? 'Invalid',
                ]);
            }

            return $isValid;

        } catch (\Exception $e) {
            Log::error('VAT validation error', [
                'payer_type' => get_class($payer),
                'payer_id' => $payer->id,
                'vat_number' => $vatNumber,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get payer model instance
     */
    private function getPayer(string $payerType, int $payerId)
    {
        if ($payerType === 'user') {
            return User::find($payerId);
        } elseif ($payerType === 'organization') {
            return Organization::find($payerId);
        }

        return null;
    }

    /**
     * Get default context for fallback scenarios
     */
    private function getDefaultContext(): array
    {
        return [
            'country' => 'NL',
            'currency' => 'EUR',
            'vat_number' => null,
            'vat_valid' => false,
            'vat_validated_at' => null,
            'payer_type' => 'user',
            'payer_id' => null,
            'payer' => null,
        ];
    }
}
