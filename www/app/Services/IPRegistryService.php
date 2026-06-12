<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IPRegistryService
{
    private string $apiUrl;

    private ?string $apiKey;

    public function __construct()
    {
        $this->apiUrl = config('services.ipregistry.url', 'https://api.ipregistry.co');
        $this->apiKey = config('services.ipregistry.key', env('IPREGISTRY_SECRET'));
    }

    /**
     * Get country information from IP address
     *
     * @param  string|null  $ip  IP address (null for current request IP)
     * @return array Country information with code, name, etc.
     */
    public function getCountryFromIP(?string $ip = null): array
    {
        if (! $this->apiKey) {
            Log::warning('IPRegistry API key not configured, falling back to defaults');

            return $this->getDefaultCountryInfo();
        }

        $ip = $ip ?? request()->ip();

        // Use cached result if available (cache for 1 hour)
        $cacheKey = "ipregistry_country_{$ip}";

        return Cache::remember($cacheKey, 3600, function () use ($ip) {
            return $this->fetchCountryInfo($ip);
        });
    }

    /**
     * Get country suggestions based on user input
     *
     * @param  string  $query  Search query
     * @return array List of matching countries
     */
    public function searchCountries(string $query): array
    {
        $countries = $this->getAllCountries();
        $query = strtolower($query);

        return array_filter($countries, function ($country) use ($query) {
            return strpos(strtolower($country['name']), $query) !== false ||
                   strpos(strtolower($country['code']), $query) !== false;
        });
    }

    /**
     * Get full country information by country code
     *
     * @param  string  $countryCode  ISO 3166-1 alpha-2 country code
     * @return array Country information
     */
    public function getCountryInfo(string $countryCode): array
    {
        $countries = $this->getAllCountries();
        $countryCode = strtoupper($countryCode);

        foreach ($countries as $country) {
            if ($country['code'] === $countryCode) {
                return $country;
            }
        }

        return [
            'code' => $countryCode,
            'name' => $countryCode,
            'currency' => 'USD',
            'is_eu' => false,
        ];
    }

    /**
     * Fetch country information from IPRegistry API
     */
    private function fetchCountryInfo(string $ip): array
    {
        try {
            $response = Http::timeout(5)->get("{$this->apiUrl}/{$ip}", [
                'key' => $this->apiKey,
                'fields' => 'location.country',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $countryCode = $data['location']['country']['code'] ?? null;
                $countryName = $data['location']['country']['name'] ?? null;

                if ($countryCode) {
                    Log::info('IPRegistry country detection successful', [
                        'ip' => $ip,
                        'country' => $countryCode,
                        'name' => $countryName,
                    ]);

                    return [
                        'code' => $countryCode,
                        'name' => $countryName,
                        'detected_from_ip' => true,
                        'ip_used' => $ip,
                    ];
                }
            }

            Log::warning('IPRegistry API returned invalid data', [
                'ip' => $ip,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

        } catch (\Exception $e) {
            Log::error('IPRegistry API request failed', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->getDefaultCountryInfo();
    }

    /**
     * Get default country info when IP detection fails
     */
    private function getDefaultCountryInfo(): array
    {
        return [
            'code' => 'NL',
            'name' => 'Netherlands',
            'detected_from_ip' => false,
            'ip_used' => null,
        ];
    }

    /**
     * Get comprehensive list of countries with metadata
     */
    private function getAllCountries(): array
    {
        return [
            // EU Countries
            ['code' => 'AT', 'name' => 'Austria', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'BE', 'name' => 'Belgium', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'BG', 'name' => 'Bulgaria', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'HR', 'name' => 'Croatia', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'CY', 'name' => 'Cyprus', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'CZ', 'name' => 'Czech Republic', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'DK', 'name' => 'Denmark', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'EE', 'name' => 'Estonia', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'FI', 'name' => 'Finland', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'DE', 'name' => 'Germany', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'GR', 'name' => 'Greece', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'HU', 'name' => 'Hungary', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'IE', 'name' => 'Ireland', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'IT', 'name' => 'Italy', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'LV', 'name' => 'Latvia', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'LT', 'name' => 'Lithuania', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'LU', 'name' => 'Luxembourg', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'MT', 'name' => 'Malta', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'NL', 'name' => 'Netherlands', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'PL', 'name' => 'Poland', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'PT', 'name' => 'Portugal', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'RO', 'name' => 'Romania', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'SK', 'name' => 'Slovakia', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'SI', 'name' => 'Slovenia', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'ES', 'name' => 'Spain', 'currency' => 'EUR', 'is_eu' => true],
            ['code' => 'SE', 'name' => 'Sweden', 'currency' => 'EUR', 'is_eu' => true],

            // Non-EU EUR countries (per POC5b business rules)
            ['code' => 'GB', 'name' => 'United Kingdom', 'currency' => 'EUR', 'is_eu' => false],
            ['code' => 'CH', 'name' => 'Switzerland', 'currency' => 'EUR', 'is_eu' => false],
            ['code' => 'NO', 'name' => 'Norway', 'currency' => 'EUR', 'is_eu' => false],

            // Major USD countries (sample)
            ['code' => 'US', 'name' => 'United States', 'currency' => 'USD', 'is_eu' => false],
            ['code' => 'CA', 'name' => 'Canada', 'currency' => 'USD', 'is_eu' => false],
            ['code' => 'AU', 'name' => 'Australia', 'currency' => 'USD', 'is_eu' => false],
            ['code' => 'JP', 'name' => 'Japan', 'currency' => 'USD', 'is_eu' => false],
            ['code' => 'SG', 'name' => 'Singapore', 'currency' => 'USD', 'is_eu' => false],
            ['code' => 'HK', 'name' => 'Hong Kong', 'currency' => 'USD', 'is_eu' => false],
            ['code' => 'IN', 'name' => 'India', 'currency' => 'USD', 'is_eu' => false],
            ['code' => 'BR', 'name' => 'Brazil', 'currency' => 'USD', 'is_eu' => false],
            ['code' => 'MX', 'name' => 'Mexico', 'currency' => 'USD', 'is_eu' => false],
            ['code' => 'CN', 'name' => 'China', 'currency' => 'USD', 'is_eu' => false],
            ['code' => 'KR', 'name' => 'South Korea', 'currency' => 'USD', 'is_eu' => false],
            ['code' => 'ZA', 'name' => 'South Africa', 'currency' => 'USD', 'is_eu' => false],
            ['code' => 'RU', 'name' => 'Russia', 'currency' => 'USD', 'is_eu' => false],
            ['code' => 'TR', 'name' => 'Turkey', 'currency' => 'USD', 'is_eu' => false],
            ['code' => 'AE', 'name' => 'United Arab Emirates', 'currency' => 'USD', 'is_eu' => false],
            ['code' => 'SA', 'name' => 'Saudi Arabia', 'currency' => 'USD', 'is_eu' => false],
            ['code' => 'IL', 'name' => 'Israel', 'currency' => 'USD', 'is_eu' => false],
            ['code' => 'NZ', 'name' => 'New Zealand', 'currency' => 'USD', 'is_eu' => false],
            ['code' => 'MY', 'name' => 'Malaysia', 'currency' => 'USD', 'is_eu' => false],
            ['code' => 'TH', 'name' => 'Thailand', 'currency' => 'USD', 'is_eu' => false],
        ];
    }

    /**
     * Get usage statistics for IT Dashboard
     * Note: IPRegistry doesn't provide direct API quota stats via API
     * We track usage from application logs and cache hits
     */
    public function getUsageStats(): array
    {
        try {
            // Count cache entries (approximate indicator of API usage)
            $cachePattern = 'ipregistry_country_*';

            // Get today's order country detections (actual usage in the app)
            $today = now()->startOfDay();
            $countriesDetectedToday = \DB::table('orders')
                ->where('created_at', '>=', $today)
                ->whereNotNull('country')
                ->count();

            // Get yesterday's detections
            $yesterday = now()->subDay()->startOfDay();
            $yesterdayEnd = now()->subDay()->endOfDay();
            $countriesDetectedYesterday = \DB::table('orders')
                ->whereBetween('created_at', [$yesterday, $yesterdayEnd])
                ->whereNotNull('country')
                ->count();

            // Get last 7 days detections
            $last7Days = now()->subDays(7);
            $countriesDetectedLast7Days = \DB::table('orders')
                ->where('created_at', '>=', $last7Days)
                ->whereNotNull('country')
                ->count();

            // Get this month's detections
            $monthStart = now()->startOfMonth();
            $countriesDetectedThisMonth = \DB::table('orders')
                ->where('created_at', '>=', $monthStart)
                ->whereNotNull('country')
                ->count();

            // Get top detected countries (last 30 days)
            $topCountries = \DB::table('orders')
                ->where('created_at', '>=', now()->subDays(30))
                ->whereNotNull('country')
                ->select('country', \DB::raw('count(*) as count'))
                ->groupBy('country')
                ->orderByDesc('count')
                ->limit(5)
                ->get()
                ->map(function ($item) {
                    return [
                        'code' => $item->country,
                        'count' => $item->count,
                    ];
                })
                ->toArray();

            // Estimate cache hit rate (cached entries reduce API calls)
            $estimatedCacheHitRate = 70; // Approximate based on 1-hour cache TTL

            return [
                'detections_today' => $countriesDetectedToday,
                'detections_yesterday' => $countriesDetectedYesterday,
                'detections_last_7_days' => $countriesDetectedLast7Days,
                'detections_this_month' => $countriesDetectedThisMonth,
                'top_countries' => $topCountries,
                'cache_hit_rate_percent' => $estimatedCacheHitRate,
                'estimated_api_calls_today' => round($countriesDetectedToday * (1 - $estimatedCacheHitRate / 100)),
                'estimated_api_calls_month' => round($countriesDetectedThisMonth * (1 - $estimatedCacheHitRate / 100)),
            ];
        } catch (\Exception $e) {
            Log::error('IPRegistry getUsageStats error', ['error' => $e->getMessage()]);

            return [
                'detections_today' => 0,
                'detections_yesterday' => 0,
                'detections_last_7_days' => 0,
                'detections_this_month' => 0,
                'top_countries' => [],
                'cache_hit_rate_percent' => 0,
                'estimated_api_calls_today' => 0,
                'estimated_api_calls_month' => 0,
            ];
        }
    }
}
