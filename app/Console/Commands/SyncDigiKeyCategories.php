<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\DigiKeyCategory;

class SyncDigiKeyCategories extends Command
{
    protected $signature = 'digikey:sync-categories';

    protected $description = 'Fetch and sync DigiKey categories and subcategories into database';

    private ?string $accessToken = null;

    public function handle()
    {
        $this->info('Starting DigiKey Categories Synchronization...');

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

        $this->info('Fetching Categories and Subcategories from DigiKey...');
        $catCount = $this->fetchAndSaveCategories($clientId, $clientSecret, $mode);
        $this->info("DigiKey Categories Sync Completed! Total saved/updated: {$catCount}");

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

    private function fetchAndSaveCategories(string $clientId, string $clientSecret, string $mode, bool $isRetry = false): int
    {
        $categoriesUrl = ($mode === 'sandbox')
            ? 'https://sandbox-api.digikey.com/products/v4/search/categories'
            : 'https://api.digikey.com/products/v4/search/categories';

        $response = Http::withHeaders([
            'X-DIGIKEY-Client-Id' => $clientId,
            'Authorization' => 'Bearer ' . $this->accessToken,
        ])->get($categoriesUrl);

        if ($response->status() === 401 || ($response->failed() && str_contains(strtolower($response->body()), 'token'))) {
            if (!$isRetry) {
                $this->warn('DigiKey Access Token expired or rejected during categories fetch. Refreshing token...');
                $this->accessToken = $this->generateAccessToken($clientId, $clientSecret, $mode);
                if ($this->accessToken) {
                    return $this->fetchAndSaveCategories($clientId, $clientSecret, $mode, true);
                }
            }
            $this->error('Failed fetching categories: Token expired and refresh failed.');
            return 0;
        }

        if (!$response->successful()) {
            $this->error('Error fetching categories: ' . $response->body());
            return 0;
        }

        $data = $response->json();
        $categories = $data['Categories'] ?? [];

        $totalSaved = 0;
        foreach ($categories as $catData) {
            $totalSaved += $this->saveCategoryNode($catData);
        }

        return $totalSaved;
    }

    private function saveCategoryNode(array $catData): int
    {
        $catId = $catData['CategoryId'] ?? null;
        if (!$catId) {
            return 0;
        }

        DigiKeyCategory::updateOrCreate(
            ['category_id' => $catId],
            [
                'parent_id' => $catData['ParentId'] ?? 0,
                'name' => $catData['Name'] ?? '',
                'product_count' => $catData['ProductCount'] ?? 0,
            ]
        );

        $savedCount = 1;

        if (!empty($catData['Children']) && is_array($catData['Children'])) {
            foreach ($catData['Children'] as $childData) {
                $savedCount += $this->saveCategoryNode($childData);
            }
        }

        return $savedCount;
    }
}
