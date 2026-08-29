<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\DigiKeyCategory;
use App\Models\DigiKeyManufacturer;
use App\Models\DigiKeyProduct;
use App\Models\DigiKeySyncState;

class DigiKeyRateLimitException extends \Exception {}

class SyncDigiKeyProducts extends Command
{
    protected $signature = 'digikey:sync 
                            {--limit=50 : Number of records per API call (max 50)} 
                            {--category= : Sync products for a specific category ID only} 
                            {--max-offset=300 : Maximum offset limit per batch slice}
                            {--max-calls=1000 : Maximum API calls before stopping for daily quota safety}
                            {--mfg-batch-size=10 : Number of manufacturers per batch filter}
                            {--start-cat-index= : Override subcategory index offset to resume from}
                            {--start-mfg-index= : Override manufacturer chunk index offset to resume from}';

    protected $description = 'Sync products from DigiKey API v4 by slicing queries with Category + Manufacturer batches with automatic DB state persistence';

    private ?string $accessToken = null;
    private int $apiCallsMade = 0;

    public function handle()
    {
        $this->info('Starting DigiKey Products Synchronization (Category + Batched Manufacturers with DB State)...');

        $clientId = env('DIGIKEY_CLIENT_ID', 'lT71SAGE5n7ZClfGSc4lLATmbnng8POpYfYrzBRsaeXuIevJ');
        $clientSecret = env('DIGIKEY_CLIENT_SECRET', '6jE42EjppYmtY6LJxOleJcRsnxAXDFs97yZ77vSZhDPrNf3V2xQYAMLU7MxWufbP');
        $mode = env('DIGIKEY_MODE', 'live');
        $limit = min((int) ($this->option('limit') ?: 50), 50);
        $specificCategory = $this->option('category');
        $maxOffsetOpt = (int) ($this->option('max-offset') ?: 300);
        $maxCalls = (int) ($this->option('max-calls') ?: 1000);
        $mfgBatchSize = max((int) ($this->option('mfg-batch-size') ?: 10), 1);

        if (!$clientId || !$clientSecret) {
            $this->error('DigiKey Client ID or Secret missing in .env');
            return 1;
        }

        // Fetch or create DB state
        $state = DigiKeySyncState::firstOrCreate(
            ['id' => 1],
            ['last_cat_index' => 0, 'last_mfg_index' => 0, 'total_synced_products' => 0]
        );

        $startCatIndex = $this->option('start-cat-index') !== null
            ? (int) $this->option('start-cat-index')
            : $state->last_cat_index;

        $startMfgIndex = $this->option('start-mfg-index') !== null
            ? (int) $this->option('start-mfg-index')
            : $state->last_mfg_index;

        try {
            $this->accessToken = $this->generateAccessToken($clientId, $clientSecret, $mode);
        } catch (DigiKeyRateLimitException $e) {
            $this->error("\nDaily API rate limit reached during OAuth token request (429 Too Many Requests).");
            $this->info("Daily limit reached. Stopping command execution.");
            return 1;
        }

        if (!$this->accessToken) {
            $this->error('Failed to obtain initial DigiKey OAuth access token.');
            return 1;
        }

        // Query subcategories
        $catQuery = DigiKeyCategory::query();
        if ($specificCategory) {
            $catQuery->where('category_id', $specificCategory);
        } else {
            $catQuery->where('parent_id', '!=', 0);
        }
        $subcategories = $catQuery->get();

        if ($subcategories->isEmpty()) {
            $this->warn('No subcategories found in database. Run `php artisan digikey:sync-categories` first.');
            return 0;
        }

        // Query manufacturers
        $manufacturers = DigiKeyManufacturer::all();
        if ($manufacturers->isEmpty()) {
            $this->warn('No manufacturers found in database. Run `php artisan digikey:sync-manufacturers` first.');
            return 0;
        }

        $mfgChunks = $manufacturers->chunk($mfgBatchSize);

        $this->info("Resuming from Subcategory Index [{$startCatIndex}], Manufacturer Chunk Index [{$startMfgIndex}]...");
        $this->info("Processing {$subcategories->count()} subcategories with {$mfgChunks->count()} manufacturer chunks (Mfg Batch: {$mfgBatchSize}, Limit: {$limit}, Max Calls: {$maxCalls})...");

        $totalSyncedProducts = 0;
        $cIdx = $startCatIndex;
        $mIdx = $startMfgIndex;

        try {
            for ($cIdx = $startCatIndex; $cIdx < $subcategories->count(); $cIdx++) {
                $subcat = $subcategories[$cIdx];
                $catSynced = 0;

                $initialMfgIdx = ($cIdx === $startCatIndex) ? $startMfgIndex : 0;

                for ($mIdx = $initialMfgIdx; $mIdx < $mfgChunks->count(); $mIdx++) {
                    if ($this->apiCallsMade >= $maxCalls) {
                        // Update state in DB before stopping
                        $state->update([
                            'last_cat_index' => $cIdx,
                            'last_mfg_index' => $mIdx,
                            'total_synced_products' => $state->total_synced_products + $totalSyncedProducts,
                        ]);

                        $this->warn("\nReached max API calls quota ({$maxCalls}). Stopping execution.");
                        $this->info("Saved state to DB: Subcategory Index={$cIdx}, Manufacturer Chunk Index={$mIdx}");
                        $this->info("Total products saved/updated in this session: {$totalSyncedProducts}");
                        return 0;
                    }

                    $mfgChunk = $mfgChunks[$mIdx];
                    $mfgIds = $mfgChunk->pluck('manufacturer_id')->toArray();

                    $offset = 0;
                    while ($offset < $maxOffsetOpt) {
                        if ($this->apiCallsMade >= $maxCalls) {
                            break;
                        }

                        $fetchedCount = $this->fetchAndSaveProductsForBatch($subcat, $mfgIds, $offset, $limit, $clientId, $clientSecret, $mode);
                        $catSynced += $fetchedCount;

                        if ($fetchedCount < $limit) {
                            // All products for this category + manufacturer slice fetched
                            break;
                        }

                        $offset += $limit;
                    }

                    // Periodically update DB progress state after each mfg chunk
                    $state->update([
                        'last_cat_index' => $cIdx,
                        'last_mfg_index' => $mIdx + 1 < $mfgChunks->count() ? $mIdx + 1 : 0,
                    ]);
                }

                $totalSyncedProducts += $catSynced;
                $this->info("Cat [{$subcat->category_id}] {$subcat->name}: Synced {$catSynced} products. (Total API calls: {$this->apiCallsMade}/{$maxCalls})");
            }
        } catch (DigiKeyRateLimitException $e) {
            $state->update([
                'last_cat_index' => $cIdx,
                'last_mfg_index' => $mIdx,
                'total_synced_products' => $state->total_synced_products + $totalSyncedProducts,
            ]);

            $this->error("\nDaily API rate limit reached from DigiKey (429 Too Many Requests / Daily Ratelimit exceeded).");
            $this->info("Saved state to DB: Subcategory Index={$cIdx}, Manufacturer Chunk Index={$mIdx}");
            $this->info("Daily limit reached. Stopping command execution.");
            return 1;
        }

        // Entire catalog cycle completed -> Reset state to 0, 0 for next sync cycle
        $state->update([
            'last_cat_index' => 0,
            'last_mfg_index' => 0,
            'total_synced_products' => $state->total_synced_products + $totalSyncedProducts,
        ]);

        $this->info("DigiKey Products Full Sync Cycle Completed! Resetting DB state to [0,0]. Total products saved: {$totalSyncedProducts}");
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

        if ($response->status() === 429 || str_contains(strtolower($response->body()), 'ratelimit') || str_contains(strtolower($response->body()), 'too many requests')) {
            throw new DigiKeyRateLimitException('Daily Ratelimit exceeded');
        }

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

    private function fetchAndSaveProductsForBatch(DigiKeyCategory $subcategory, array $mfgIds, int $offset, int $limit, string $clientId, string $clientSecret, string $mode, bool $isRetry = false): int
    {
        $searchUrl = ($mode === 'sandbox')
            ? 'https://sandbox-api.digikey.com/products/v4/search/keyword'
            : 'https://api.digikey.com/products/v4/search/keyword';

        $filterOptions = [
            'CategoryFilter' => [
                [
                    'Id' => (int) $subcategory->category_id,
                ]
            ]
        ];

        if (!empty($mfgIds)) {
            $filterOptions['ManufacturerFilter'] = array_map(function ($id) {
                return ['Id' => (int) $id];
            }, $mfgIds);
        }

        $payload = [
            'Keywords' => '',
            'Limit' => $limit,
            'Offset' => $offset,
            'FilterOptionsRequest' => $filterOptions
        ];

        $this->apiCallsMade++;

        $response = Http::withHeaders([
            'X-DIGIKEY-Client-Id' => $clientId,
            'Authorization' => 'Bearer ' . $this->accessToken,
            'X-DIGIKEY-Locale-Site' => 'IN',
            'X-DIGIKEY-Locale-Currency' => 'INR',
            'Content-Type' => 'application/json',
        ])->post($searchUrl, $payload);

        if ($response->status() === 429 || str_contains(strtolower($response->body()), 'ratelimit') || str_contains(strtolower($response->body()), 'too many requests')) {
            throw new DigiKeyRateLimitException('Daily Ratelimit exceeded');
        }

        if ($response->status() === 401 || ($response->failed() && str_contains(strtolower($response->body()), 'token'))) {
            if (!$isRetry) {
                $this->warn("DigiKey Access Token expired during request. Refreshing token...");
                $this->accessToken = $this->generateAccessToken($clientId, $clientSecret, $mode);
                if ($this->accessToken) {
                    return $this->fetchAndSaveProductsForBatch($subcategory, $mfgIds, $offset, $limit, $clientId, $clientSecret, $mode, true);
                }
            }
            $this->error("Failed fetching products for Cat {$subcategory->category_id} and Mfg Batch (" . implode(',', $mfgIds) . "): Token refresh failed.");
            return 0;
        }

        if (!$response->successful()) {
            $this->error("Error fetching products for Cat {$subcategory->category_id} and Mfg Batch (" . implode(',', $mfgIds) . "): " . $response->body());
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
            if (!empty($p['ProductVariations']) && is_array($p['ProductVariations'])) {
                foreach ($p['ProductVariations'] as $pv) {
                    if (!empty($pv['DigiKeyProductNumber'])) {
                        $digiKeyPartNum = $pv['DigiKeyProductNumber'];
                        break;
                    }
                }
            }

            DigiKeyProduct::updateOrCreate(
                ['digikey_product_number' => $digiKeyPartNum],
                [
                    'category_id' => $subcategory->category_id,
                    'manufacturer_product_number' => $mfgPartNum,
                    'manufacturer_name' => $p['Manufacturer']['Name'] ?? null,
                    'manufacturer_id' => $p['Manufacturer']['Id'] ?? null,
                    'product_description' => $p['Description']['ProductDescription'] ?? null,
                    'detailed_description' => $p['Description']['DetailedDescription'] ?? null,
                    'unit_price' => $p['UnitPrice'] ?? 0,
                    'product_url' => $p['ProductUrl'] ?? null,
                    'datasheet_url' => $p['DatasheetUrl'] ?? null,
                    'photo_url' => $p['PhotoUrl'] ?? null,
                    'product_variations' => $p['ProductVariations'] ?? [],
                    'parameters' => $p['Parameters'] ?? [],
                    'classifications' => $p['Classifications'] ?? [],
                    'series' => $p['Series'] ?? [],
                    'other_names' => $p['OtherNames'] ?? [],
                    'base_product_number' => $p['BaseProductNumber'] ?? null,
                    'category_details' => $p['Category'] ?? null,
                    'date_last_buy_chance' => $p['DateLastBuyChance'] ?? null,
                    'shipping_info' => $p['ShippingInfo'] ?? null,
                    'back_order_not_allowed' => (bool) ($p['BackOrderNotAllowed'] ?? false),
                    'normally_stocking' => (bool) ($p['NormallyStocking'] ?? true),
                    'discontinued' => (bool) ($p['Discontinued'] ?? false),
                    'end_of_life' => (bool) ($p['EndOfLife'] ?? false),
                    'ncnr' => (bool) ($p['Ncnr'] ?? false),
                    'primary_video_url' => $p['PrimaryVideoUrl'] ?? null,
                    'manufacturer_lead_weeks' => $p['ManufacturerLeadWeeks'] ?? null,
                    'manufacturer_public_quantity' => $p['ManufacturerPublicQuantity'] ?? 0,
                    'quantity_available' => $p['QuantityAvailable'] ?? 0,
                    'product_status' => $p['ProductStatus']['Status'] ?? 'Active',
                    'search_keyword' => $subcategory->name
                ]
            );
            $count++;
        }

        return $count;
    }
}
