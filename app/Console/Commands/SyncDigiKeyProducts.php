<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\DigiKeyCategory;
use App\Models\DigiKeyManufacturer;
use App\Models\DigiKeyProduct;
use App\Models\DigiKeySyncState;
use App\Models\DigiKeyAccount;

class DigiKeyRateLimitException extends \Exception {}
class DigiKeyAccountException extends \Exception {}

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

    protected $description = 'Sync products from DigiKey API v4 using multi-account rotation with Category + Manufacturer batches and automatic DB state persistence';

    private ?DigiKeyAccount $currentAccount = null;
    private ?string $accessToken = null;
    private int $apiCallsMade = 0;

    public function handle()
    {
        $this->info('Starting DigiKey Products Synchronization (Multi-Account + Batched Manufacturers with DB State)...');

        // Ensure fallback initial account exists in digikey_accounts if empty
        $this->ensureAccountExists();

        $limit = min((int) ($this->option('limit') ?: 50), 50);
        $specificCategory = $this->option('category');
        $maxOffsetOpt = (int) ($this->option('max-offset') ?: 300);
        $maxCalls = (int) ($this->option('max-calls') ?: 1000);
        $mfgBatchSize = max((int) ($this->option('mfg-batch-size') ?: 10), 1);

        // Fetch initial active account
        if (!$this->switchToNextAccount()) {
            $this->error('No active or usable DigiKey accounts found in `digikey_accounts` table or environment.');
            return 1;
        }

        // Fetch or create DB state
        $state = DigiKeySyncState::firstOrCreate(
            ['id' => 1],
            [
                'last_cat_index' => 0,
                'last_mfg_index' => 0,
                'total_synced_products' => 0
            ]
        );

        $startCatIndex = $this->option('start-cat-index') !== null
            ? (int) $this->option('start-cat-index')
            : $state->last_cat_index;

        $startMfgIndex = $this->option('start-mfg-index') !== null
            ? (int) $this->option('start-mfg-index')
            : $state->last_mfg_index;

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

                        $fetchedCount = $this->fetchAndSaveProductsWithAccountFallback($subcat, $mfgIds, $offset, $limit);
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

            $this->error("\nAll DigiKey accounts reached daily rate limit or exhausted.");
            $this->info("Saved state to DB: Subcategory Index={$cIdx}, Manufacturer Chunk Index={$mIdx}");
            $this->info("Stopping command execution.");
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

    /**
     * Seed initial account into digikey_accounts table if empty using CredentialService / .env values.
     */
    private function ensureAccountExists(): void
    {
        if (DigiKeyAccount::count() === 0) {
            $clientId = \App\Services\CredentialService::get('digikey', 'DIGIKEY_CLIENT_ID', 'DIGIKEY_CLIENT_ID', 'lT71SAGE5n7ZClfGSc4lLATmbnng8POpYfYrzBRsaeXuIevJ');
            $clientSecret = \App\Services\CredentialService::get('digikey', 'DIGIKEY_CLIENT_SECRET', 'DIGIKEY_CLIENT_SECRET', '6jE42EjppYmtY6LJxOleJcRsnxAXDFs97yZ77vSZhDPrNf3V2xQYAMLU7MxWufbP');
            $mode = \App\Services\CredentialService::get('digikey', 'DIGIKEY_MODE', 'DIGIKEY_MODE', 'live');

            if (!empty($clientId) && !empty($clientSecret)) {
                DigiKeyAccount::create([
                    'account_name' => 'Primary DigiKey Account',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'mode' => $mode,
                    'is_active' => true,
                    'status' => 'active',
                ]);
                $this->info('Seeded primary DigiKey account into `digikey_accounts` table.');
            }
        }
    }

    /**
     * Switch to the next available and usable DigiKey account.
     */
    private function switchToNextAccount(?int $excludeId = null): bool
    {
        $query = DigiKeyAccount::usable();
        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        $accounts = $query->orderBy('last_used_at', 'asc')->get();

        foreach ($accounts as $account) {
            try {
                $token = $this->generateAccessTokenForAccount($account);
                if ($token) {
                    $this->currentAccount = $account;
                    $this->accessToken = $token;
                    $account->touchUsed();
                    $this->info("Successfully switched to DigiKey Account: [ID: {$account->id}] {$account->account_name}");
                    return true;
                }
            } catch (DigiKeyRateLimitException $e) {
                $this->warn("Account [ID: {$account->id}] {$account->account_name} rate limit reached. Marking rate limited.");
                $account->markRateLimited($e->getMessage());
            } catch (\Exception $e) {
                $this->warn("Account [ID: {$account->id}] {$account->account_name} failed: " . $e->getMessage());
                $account->markError($e->getMessage());
            }
        }

        $this->currentAccount = null;
        $this->accessToken = null;
        return false;
    }

    /**
     * Generate OAuth access token for a specific DigiKey account record.
     */
    private function generateAccessTokenForAccount(DigiKeyAccount $account): ?string
    {
        $clientId = $account->client_id;
        $clientSecret = $account->decrypted_client_secret;
        $mode = $account->mode ?? 'live';

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
                return $token;
            }
        }

        $errorMsg = 'OAuth Error (' . $response->status() . '): ' . $response->body();
        Log::error('DigiKey OAuth Token Error', ['account_id' => $account->id, 'body' => $response->body()]);
        throw new DigiKeyAccountException($errorMsg);
    }

    /**
     * Wrapper function that handles API requests with automatic account fallback on rate limits or errors.
     */
    private function fetchAndSaveProductsWithAccountFallback(DigiKeyCategory $subcategory, array $mfgIds, int $offset, int $limit): int
    {
        while ($this->currentAccount !== null) {
            try {
                return $this->fetchAndSaveProductsForBatch($subcategory, $mfgIds, $offset, $limit);
            } catch (DigiKeyRateLimitException $e) {
                $this->warn("\nRate limit (429) hit on DigiKey Account [ID: {$this->currentAccount->id}] {$this->currentAccount->account_name}. Switching account...");
                $this->currentAccount->markRateLimited($e->getMessage());
                if (!$this->switchToNextAccount($this->currentAccount->id)) {
                    throw $e; // No more accounts available
                }
            } catch (DigiKeyAccountException $e) {
                $this->warn("\nAPI error hit on DigiKey Account [ID: {$this->currentAccount->id}] {$this->currentAccount->account_name}: {$e->getMessage()}. Switching account...");
                $this->currentAccount->markError($e->getMessage());
                if (!$this->switchToNextAccount($this->currentAccount->id)) {
                    throw new DigiKeyRateLimitException('All accounts failed or rate limited');
                }
            }
        }

        throw new DigiKeyRateLimitException('No active DigiKey account available');
    }

    private function fetchAndSaveProductsForBatch(DigiKeyCategory $subcategory, array $mfgIds, int $offset, int $limit, bool $isRetry = false): int
    {
        if (!$this->currentAccount || !$this->accessToken) {
            throw new DigiKeyAccountException('No active DigiKey account session available');
        }

        $clientId = $this->currentAccount->client_id;
        $mode = $this->currentAccount->mode ?? 'live';

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
                $this->warn("DigiKey Access Token expired during request. Refreshing token for current account...");
                try {
                    $this->accessToken = $this->generateAccessTokenForAccount($this->currentAccount);
                    if ($this->accessToken) {
                        return $this->fetchAndSaveProductsForBatch($subcategory, $mfgIds, $offset, $limit, true);
                    }
                } catch (\Exception $ex) {
                    throw new DigiKeyAccountException('Token refresh failed: ' . $ex->getMessage());
                }
            }
            throw new DigiKeyAccountException('Unauthorized (401) token error');
        }

        if (!$response->successful()) {
            $this->error("Error fetching products for Cat {$subcategory->category_id} and Mfg Batch (" . implode(',', $mfgIds) . "): " . $response->body());
            throw new DigiKeyAccountException('HTTP Error ' . $response->status() . ': ' . $response->body());
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

