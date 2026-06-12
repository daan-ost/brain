<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CurrencyConversionService
{
    private const CACHE_KEY = 'currency_exchange_rates';

    private const CACHE_DURATION = 3600; // 1 hour

    public function convertEurToUsd(float $eurAmount): float
    {
        $rate = $this->getEurToUsdRate();

        return round($eurAmount * $rate, 2);
    }

    public function convertUsdToEur(float $usdAmount): float
    {
        $rate = $this->getEurToUsdRate();

        return round($usdAmount / $rate, 2);
    }

    private function getEurToUsdRate(): float
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_DURATION, function () {
            try {
                // Using exchangerate-api.com free tier (1500 requests/month)
                $response = Http::timeout(5)->get('https://api.exchangerate-api.com/v4/latest/EUR');

                if ($response->successful()) {
                    $data = $response->json();

                    return $data['rates']['USD'] ?? 1.1; // Fallback rate
                }
            } catch (\Exception $e) {
                Log::warning('Currency conversion API failed', ['error' => $e->getMessage()]);
            }

            // Fallback to approximate rate if API fails
            return 1.1;
        });
    }
}
