<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\DigiKeyManufacturer;

class SyncDigiKeyManufacturers extends Command
{
    protected $signature = 'digikey:sync-manufacturers';

    protected $description = 'Fetch and sync DigiKey manufacturers into database';

    private ?string $accessToken = null;

    public function handle()
    {
        $this->info('Starting DigiKey Manufacturers Synchronization...');

        $clientId = env('DIGIKEY_CLIENT_ID', 'lT71SAGE5n7ZClfGSc4lLATmbnng8POpYfYrzBRsaeXuIevJ');
        $clientSecret = env('DIGIKEY_CLIENT_SECRET', '6jE42EjppYmtY6LJxOleJcRsnxAXDFs97yZ77vSZhDPrNf3V2xQYAMLU7MxWufbP');
        $mode = env('DIGIKEY_MODE', 'live');

        if (!$clientId || !$clientSecret) {
            $this->error('DigiKey Client ID or Secret missing in .env');
            return 1;
        }

        $this->accessToken = $this->generateAccessToken($clientId, $clientSecret, $mode);
        if (!$this->accessToken) {
            $this->error('Failed to obtain initial DigiKey OAuth access token.');
            return 1;
        }

        $this->info('Fetching Manufacturers from DigiKey...');
        $savedCount = $this->fetchAndSaveManufacturers($clientId, $clientSecret, $mode);
        $this->info("DigiKey Manufacturers Sync Completed! Total saved/updated: {$savedCount}");

        return 0;
    }

    private function generateAccessToken(string $clientId, string $clientSecret, string $mode): ?string
    {
        $url = ($mode === 'sandbox')
            ? 'https://sandbox-api.digikey.com/v1/oauth2/token'
            : 'https://api.digikey.com/v1/oauth2/token';

        $response = Http::asForm()->post($url, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'client_credentials',
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $token = $data['access_token'] ?? null;
            if ($token) {
                $this->info('Obtained fresh DigiKey OAuth token successfully.');
                return $token;
            }
        }

        $this->error('OAuth Error: ' . $response->body());
        Log::error('DigiKey OAuth Token Error', ['body' => $response->body()]);
        return null;
    }

    private function fetchAndSaveManufacturers(string $clientId, string $clientSecret, string $mode, bool $isRetry = false): int
    {
        $url = ($mode === 'sandbox')
            ? 'https://sandbox-api.digikey.com/products/v4/search/manufacturers'
            : 'https://api.digikey.com/products/v4/search/manufacturers';

        $response = Http::withHeaders([
            'X-DIGIKEY-Client-Id' => $clientId,
            'Authorization' => 'Bearer ' . $this->accessToken,
        ])->get($url);

        if ($response->status() === 401 || ($response->failed() && str_contains(strtolower($response->body()), 'token'))) {
            if (!$isRetry) {
                $this->warn('DigiKey Access Token expired or rejected during manufacturers fetch. Refreshing token...');
                $this->accessToken = $this->generateAccessToken($clientId, $clientSecret, $mode);
                if ($this->accessToken) {
                    return $this->fetchAndSaveManufacturers($clientId, $clientSecret, $mode, true);
                }
            }
            $this->error('Failed fetching manufacturers: Token expired and refresh failed.');
            return 0;
        }

        if (!$response->successful()) {
            $this->error('Error fetching manufacturers: ' . $response->body());
            return 0;
        }

        $data = $response->json();
        $manufacturers = $data['Manufacturers'] ?? [];

        $savedCount = 0;
        foreach ($manufacturers as $mfg) {
            $mfgId = $mfg['Id'] ?? null;
            if (!$mfgId) {
                continue;
            }

            DigiKeyManufacturer::updateOrCreate(
                ['manufacturer_id' => $mfgId],
                [
                    'name' => $mfg['Name'] ?? '',
                ]
            );
            $savedCount++;
        }

        return $savedCount;
    }
}
