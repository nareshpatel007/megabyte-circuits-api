<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CurrencyConversionService
{
    /**
     * Get USD to INR exchange rate with caching and fallbacks
     *
     * @param bool $forceRefresh
     * @return float
     */
    public static function getUsdToInrRate(bool $forceRefresh = false): float
    {
        if ($forceRefresh) {
            Cache::forget('fx_usd_inr_rate');
        }

        return Cache::remember('fx_usd_inr_rate', 3600, function () {
            try {
                // Primary FX API: open.er-api.com
                $response = Http::timeout(4)->get('https://open.er-api.com/v6/latest/USD');
                if ($response->successful()) {
                    $rates = $response->json('rates');
                    if (isset($rates['INR']) && is_numeric($rates['INR']) && $rates['INR'] > 0) {
                        $rate = floatval($rates['INR']);
                        Log::info("FX Rate fetched from open.er-api: 1 USD = {$rate} INR");
                        return $rate;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("Primary FX API failed: " . $e->getMessage());
            }

            try {
                // Secondary FX API: exchangerate-api fallback
                $response = Http::timeout(4)->get('https://api.exchangerate-api.com/v4/latest/USD');
                if ($response->successful()) {
                    $rates = $response->json('rates');
                    if (isset($rates['INR']) && is_numeric($rates['INR']) && $rates['INR'] > 0) {
                        $rate = floatval($rates['INR']);
                        Log::info("FX Rate fetched from exchangerate-api: 1 USD = {$rate} INR");
                        return $rate;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("Secondary FX API failed: " . $e->getMessage());
            }

            // Fallback to configured credential / env rate or default 95.50
            $fallback = floatval(CredentialService::get('currency', 'USD_INR_EXCHANGE_RATE', 'USD_INR_EXCHANGE_RATE', config('services.currency.usd_inr_rate', 95.50)));
            Log::info("FX Rate using configured fallback: 1 USD = {$fallback} INR");
            return $fallback;
        });
    }

    /**
     * Convert USD price to INR array structure
     *
     * @param float $usdAmount
     * @return array
     */
    public static function convertUsdToInr(float $usdAmount): array
    {
        $rate = self::getUsdToInrRate();
        $inrPrice = round($usdAmount * $rate, 2);

        return [
            'currency' => 'INR',
            'exchange_rate' => $rate,
            'usd_price' => round($usdAmount, 2),
            'inr_price' => $inrPrice
        ];
    }
}
