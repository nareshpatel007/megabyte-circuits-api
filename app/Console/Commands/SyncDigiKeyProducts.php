<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\DigiKeyProduct;

class SyncDigiKeyProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'digikey:sync {--keyword= : Sync a specific keyword only} {--count=20 : Number of records per keyword}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync products from DigiKey API v4 OAuth2 into database';

    /**
     * List of target search keywords requested
     */
    protected array $keywords = [
        'resistor',
        'capacitor',
        'LED',
        'diode',
        'transistor',
        'MOSFET',
        'microcontroller',
        'Arduino',
        'ESP32',
        'Raspberry Pi',
        'connector',
        'relay',
        'switch',
        'voltage regulator',
        'op amp',
        'IC',
        'PCB',
        'sensor',
        'USB',
        '10k resistor',
        'LM358',
        'NE555',
        'STM32',
        'ATmega328P',
    ];

    private ?string $accessToken = null;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting DigiKey Product Synchronization...');

        $clientId = env('DIGIKEY_CLIENT_ID', 'lT71SAGE5n7ZClfGSc4lLATmbnng8POpYfYrzBRsaeXuIevJ');
        $clientSecret = env('DIGIKEY_CLIENT_SECRET', '6jE42EjppYmtY6LJxOleJcRsnxAXDFs97yZ77vSZhDPrNf3V2xQYAMLU7MxWufbP');
        $mode = env('DIGIKEY_MODE', 'live');
        $recordCount = (int) ($this->option('count') ?: 10000);

        if (!$clientId || !$clientSecret) {
            $this->error('DigiKey Client ID or Secret missing in .env');
            return 1;
        }

        // Generate initial token
        $this->accessToken = $this->generateAccessToken($clientId, $clientSecret, $mode);
        if (!$this->accessToken) {
            $this->error('Failed to obtain initial DigiKey OAuth access token.');
            return 1;
        }

        $specificKeyword = $this->option('keyword');
        $targetKeywords = $specificKeyword ? [$specificKeyword] : $this->keywords;

        $totalSynced = 0;

        foreach ($targetKeywords as $kw) {
            $this->info("Fetching products for keyword: '{$kw}'...");
            $syncedCount = $this->fetchAndSaveProducts($kw, $recordCount, $clientId, $clientSecret, $mode);
            $totalSynced += $syncedCount;
            $this->info("Successfully synced {$syncedCount} products for '{$kw}'.");
            // Gentle sleep between calls to respect rate limits
            usleep(300000);
        }

        $this->info("DigiKey Synchronization Completed! Total products saved/updated: {$totalSynced}");
        return 0;
    }

    /**
     * Helper to parse integer option safely
     */
    private function RepublicOrArgument(string $name, int $default): int
    {
        $val = $this->option($name);
        return $val ? (int)$val : $default;
    }

    /**
     * Generate OAuth2 Access Token
     */
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

    /**
     * Fetch products for a keyword and handle automatic token refresh on 401 / expired token
     */
    private function fetchAndSaveProducts(string $keyword, int $recordCount, string $clientId, string $clientSecret, string $mode, bool $isRetry = false): int
    {
        $searchUrl = ($mode === 'sandbox')
            ? 'https://sandbox-api.digikey.com/products/v4/search/keyword'
            : 'https://api.digikey.com/products/v4/search/keyword';

        $response = Http::withHeaders([
            'X-DIGIKEY-Client-Id' => $clientId,
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Content-Type' => 'application/json',
        ])->post($searchUrl, [
            'Keywords' => $keyword,
            'RecordCount' => $recordCount,
            'RecordStartPosition' => 0,
        ]);

        // Check if token expired (401 or specific error payload)
        if ($response->status() === 401 || ($response->failed() && str_contains(strtolower($response->body()), 'token'))) {
            if (!$isRetry) {
                $this->warn('DigiKey Access Token expired or rejected. Refreshing token and retrying...');
                $this->accessToken = $this->generateAccessToken($clientId, $clientSecret, $mode);
                if ($this->accessToken) {
                    return $this->fetchAndSaveProducts($keyword, $recordCount, $clientId, $clientSecret, $mode, true);
                }
            }
            $this->error("Failed fetching for keyword '{$keyword}': Token expired and refresh failed.");
            return 0;
        }

        if (!$response->successful()) {
            $this->error("Error response for keyword '{$keyword}': " . $response->body());
            return 0;
        }

        $json = $response->json();
        $products = $json['Products'] ?? [];

        $count = 0;
        foreach ($products as $p) {
            $mfgPartNum = $p['ManufacturerProductNumber'] ?? null;
            if (!$mfgPartNum) {
                continue;
            }

            $digiKeyPartNum = null;
            if (!empty($p['ProductVariations'][0]['DigiKeyProductNumber'])) {
                $digiKeyPartNum = $p['ProductVariations'][0]['DigiKeyProductNumber'];
            }

            $unitPrice = $p['UnitPrice'] ?? 0;

            DigiKeyProduct::updateOrCreate(
                ['manufacturer_product_number' => $mfgPartNum],
                [
                    'digikey_product_number' => $digiKeyPartNum,
                    'manufacturer_name' => $p['Manufacturer']['Name'] ?? null,
                    'manufacturer_id' => $p['Manufacturer']['Id'] ?? null,
                    'product_description' => $p['Description']['ProductDescription'] ?? null,
                    'detailed_description' => $p['Description']['DetailedDescription'] ?? null,
                    'unit_price' => $unitPrice,
                    'product_url' => $p['ProductUrl'] ?? null,
                    'datasheet_url' => $p['DatasheetUrl'] ?? null,
                    'photo_url' => $p['PhotoUrl'] ?? null,
                    'quantity_available' => $p['QuantityAvailable'] ?? 0,
                    'product_status' => $p['ProductStatus']['Status'] ?? 'Active',
                    'search_keyword' => $keyword,
                    'raw_response' => $p,
                ]
            );
            $count++;
        }

        return $count;
    }
}
