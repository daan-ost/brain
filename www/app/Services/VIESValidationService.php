<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VIESValidationService
{
    private const VIES_API_URL = 'https://ec.europa.eu/taxation_customs/vies/rest-api';

    /**
     * Validate EU VAT number via VIES service
     *
     * @param  string  $vatId  Full VAT ID with country prefix (e.g., "NL123456789B01")
     * @return array Validation result with status and details
     */
    public function validateVatId(string $vatId): array
    {
        $vatId = $this->normalizeVatId($vatId);

        if (! $this->isValidFormat($vatId)) {
            return [
                'valid' => false,
                'error' => 'Invalid VAT ID format',
                'vat_id' => $vatId,
                'checked_at' => now(),
                'source' => 'format_validation',
            ];
        }

        $countryCode = substr($vatId, 0, 2);
        $vatNumber = substr($vatId, 2);

        // Cache validation results for 1 hour to avoid API spam
        $cacheKey = "vies_validation_{$vatId}";

        return Cache::remember($cacheKey, 3600, function () use ($countryCode, $vatNumber, $vatId) {
            return $this->performViesValidation($countryCode, $vatNumber, $vatId);
        });
    }

    /**
     * Extract country code from VAT ID
     */
    public function extractCountryCode(string $vatId): ?string
    {
        $vatId = $this->normalizeVatId($vatId);

        if (strlen($vatId) < 2) {
            return null;
        }

        $countryCode = substr($vatId, 0, 2);

        // Validate that it's a valid EU country code
        $euCountries = [
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
            'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
            'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        ];

        return in_array($countryCode, $euCountries) ? $countryCode : null;
    }

    /**
     * Check if VAT ID format is potentially valid
     */
    public function isValidFormat(string $vatId): bool
    {
        $vatId = $this->normalizeVatId($vatId);

        // Basic format check: 2-letter country code + alphanumeric
        if (! preg_match('/^[A-Z]{2}[A-Z0-9]{2,12}$/', $vatId)) {
            return false;
        }

        $countryCode = substr($vatId, 0, 2);

        return $this->extractCountryCode($vatId) === $countryCode;
    }

    /**
     * Get VAT ID format requirements by country
     */
    public function getVatIdFormat(string $countryCode): array
    {
        $formats = [
            'NL' => ['pattern' => 'NL123456789B01', 'description' => '9 digits + B + 2 digits'],
            'DE' => ['pattern' => 'DE123456789', 'description' => '9 digits'],
            'FR' => ['pattern' => 'FR12345678901', 'description' => '11 characters (letters or digits)'],
            'BE' => ['pattern' => 'BE0123456789', 'description' => '10 digits'],
            'IT' => ['pattern' => 'IT12345678901', 'description' => '11 digits'],
            'ES' => ['pattern' => 'ES12345678A', 'description' => '8 digits + 1 letter'],
            'GB' => ['pattern' => 'GB123456789', 'description' => '9 or 12 digits'],
        ];

        return $formats[$countryCode] ?? [
            'pattern' => $countryCode.'XXXXXXXXX',
            'description' => 'Country-specific format required',
        ];
    }

    /**
     * Perform actual VIES API validation
     */
    private function performViesValidation(string $countryCode, string $vatNumber, string $fullVatId): array
    {
        try {
            $response = Http::timeout(10)->get(
                self::VIES_API_URL."/ms/{$countryCode}/vat/{$vatNumber}"
            );

            if ($response->successful()) {
                $data = $response->json();

                // VIES API uses 'isValid' (boolean/int), not 'valid'
                $isValid = ($data['isValid'] ?? $data['valid'] ?? false) == true;

                Log::info('VIES validation completed', [
                    'vat_id' => $fullVatId,
                    'country' => $countryCode,
                    'vat_number' => $vatNumber,
                    'valid' => $isValid,
                    'company_name' => $data['name'] ?? null,
                    'company_address' => $data['address'] ?? null,
                ]);

                return [
                    'valid' => $isValid,
                    'vat_id' => $fullVatId,
                    'country_code' => $countryCode,
                    'vat_number' => $vatNumber,
                    'company_name' => $data['name'] ?? null,
                    'company_address' => $data['address'] ?? null,
                    'checked_at' => now(),
                    'source' => 'vies_api',
                    'raw_response' => $data,
                ];
            }

            Log::warning('VIES API returned error status', [
                'vat_id' => $fullVatId,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

        } catch (\Exception $e) {
            Log::error('VIES API request failed', [
                'vat_id' => $fullVatId,
                'error' => $e->getMessage(),
            ]);
        }

        // If validation fails or API is unavailable, return inconclusive result
        // Per POC5b spec: proceed as consumer (apply VAT) but store the VAT ID attempt
        return [
            'valid' => false,
            'vat_id' => $fullVatId,
            'country_code' => $countryCode,
            'vat_number' => $vatNumber,
            'error' => 'VIES service unavailable or VAT ID invalid',
            'checked_at' => now(),
            'source' => 'vies_api_failed',
            'fallback_applied' => true,
        ];
    }

    /**
     * Normalize VAT ID format (uppercase, remove spaces/dashes)
     */
    private function normalizeVatId(string $vatId): string
    {
        // Remove common separators and convert to uppercase
        $normalized = strtoupper(str_replace([' ', '-', '.', ','], '', $vatId));

        // Remove VAT prefix if present
        if (str_starts_with($normalized, 'VAT')) {
            $normalized = substr($normalized, 3);
        }

        return $normalized;
    }

    /**
     * Get user-friendly error messages for validation failures
     */
    public function getErrorMessage(array $validationResult): string
    {
        if ($validationResult['valid']) {
            return '';
        }

        $error = $validationResult['error'] ?? 'Unknown error';

        if (str_contains($error, 'format')) {
            return 'VAT ID format is invalid. Please check the format for your country.';
        }

        if (str_contains($error, 'unavailable')) {
            return 'VAT validation service is temporarily unavailable. Your order will proceed with standard VAT rates.';
        }

        return 'VAT ID could not be validated. Please check the number or contact support.';
    }

    /**
     * Check if validation should be attempted for this country
     */
    public function shouldValidateForCountry(string $countryCode): bool
    {
        return $this->extractCountryCode($countryCode.'TEST') === $countryCode;
    }
}
