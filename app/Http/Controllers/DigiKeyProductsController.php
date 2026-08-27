<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DigiKeyProduct;
use Illuminate\Support\Facades\Artisan;

class DigiKeyProductsController extends Controller
{
    /**
     * Get products stored in database with optional keyword filtering and pagination
     */
    public function index(Request $request)
    {
        $keyword = trim($request->query('keywords') ?? $request->query('keyword') ?? '');
        $defaultLimit = (int) (config('services.digikey.featured_count') ?? env('FEATURED_PRODUCTS_COUNT', 4));
        $page = max(1, (int) $request->query('page', 1));
        $limit = (int) ($request->query('count') ?? $request->query('limit') ?? $request->query('per_page') ?? $defaultLimit);
        $offset = ($page - 1) * $limit;

        $category = trim($request->query('category') ?? '');

        $query = DigiKeyProduct::query();

        if (!empty($category) && strtolower($category) !== 'all') {
            $query->where('search_keyword', 'like', "%{$category}%");
        }

        if (!empty($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('search_keyword', 'like', "%{$keyword}%")
                  ->orWhere('manufacturer_product_number', 'like', "%{$keyword}%")
                  ->orWhere('product_description', 'like', "%{$keyword}%")
                  ->orWhere('detailed_description', 'like', "%{$keyword}%")
                  ->orWhere('manufacturer_name', 'like', "%{$keyword}%");
            });
        }

        if (empty($category) && empty($keyword) && ($limit <= 4 || $request->boolean('distinct_category'))) {
            $distinctIds = DigiKeyProduct::selectRaw('MIN(id) as min_id')
                ->whereNotNull('search_keyword')
                ->groupBy('search_keyword');
            $query->whereIn('id', $distinctIds);
        }

        $totalCount = $query->count();
        $products = $query->orderBy('id', 'asc')->skip($offset)->take($limit)->get();

        // Get all categories & subcategories list from digikey_categories table
        $allCategories = \App\Models\DigiKeyCategory::where('parent_id', '!=', 0)
            ->select('name', 'product_count as count')
            ->orderBy('name', 'asc')
            ->get();

        if ($allCategories->isEmpty()) {
            $allCategories = DigiKeyProduct::selectRaw('search_keyword as name, COUNT(*) as count')
                ->whereNotNull('search_keyword')
                ->groupBy('search_keyword')
                ->orderBy('search_keyword', 'asc')
                ->get();
        }

        // Merge actual stored DB product counts for active categories
        $dbCounts = DigiKeyProduct::selectRaw('search_keyword as name, COUNT(*) as count')
            ->whereNotNull('search_keyword')
            ->groupBy('search_keyword')
            ->pluck('count', 'name');

        $categories = $allCategories->map(function ($cat) use ($dbCounts) {
            $dbCount = $dbCounts[$cat->name] ?? 0;
            return [
                'name' => $cat->name,
                'count' => $dbCount > 0 ? (int)$dbCount : (int)$cat->count
            ];
        })->unique('name')->values();

        // Map format for frontend standard
        $formatted = $products->map(function ($item) {
            return [
                'id' => $item->id,
                'ManufacturerProductNumber' => $item->manufacturer_product_number,
                'Description' => [
                    'ProductDescription' => $item->product_description,
                    'DetailedDescription' => $item->detailed_description,
                ],
                'Manufacturer' => [
                    'Id' => $item->manufacturer_id,
                    'Name' => $item->manufacturer_name,
                ],
                'UnitPrice' => (float) $item->unit_price,
                'ProductUrl' => $item->product_url,
                'DatasheetUrl' => $item->datasheet_url,
                'PhotoUrl' => $item->photo_url,
                'QuantityAvailable' => (int) $item->quantity_available,
                'ProductStatus' => [
                    'Status' => $item->product_status ?? 'Active'
                ],
                'Category' => $item->search_keyword,
                'ProductVariations' => [
                    [
                        'DigiKeyProductNumber' => $item->digikey_product_number
                    ]
                ]
            ];
        });

        $totalPages = ceil($totalCount / ($limit > 0 ? $limit : 1));

        return response()->json([
            'Products' => $formatted,
            'ProductsCount' => $totalCount,
            'Page' => $page,
            'PerPage' => $limit,
            'TotalPages' => $totalPages,
            'Categories' => $categories
        ]);
    }

    /**
     * Get single product details by part number
     */
    public function show($partNumber)
    {
        $item = DigiKeyProduct::where('manufacturer_product_number', $partNumber)
            ->orWhere('digikey_product_number', $partNumber)
            ->first();

        if (!$item) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        $raw = is_array($item->raw_response) ? $item->raw_response : json_decode($item->raw_response ?? '{}', true);

        // Get all DB table columns as array
        $data = $item->toArray();

        // Merge DB columns with DigiKey format keys for full frontend compatibility
        $response = array_merge($data, [
            'ManufacturerProductNumber' => $item->manufacturer_product_number,
            'Description' => [
                'ProductDescription' => $item->product_description,
                'DetailedDescription' => $item->detailed_description,
            ],
            'Manufacturer' => [
                'Id' => $item->manufacturer_id,
                'Name' => $item->manufacturer_name,
            ],
            'UnitPrice' => (float) $item->unit_price,
            'ProductUrl' => $item->product_url,
            'DatasheetUrl' => $item->datasheet_url,
            'PhotoUrl' => $item->photo_url,
            'QuantityAvailable' => (int) $item->quantity_available,
            'ProductStatus' => [
                'Status' => $item->product_status ?? 'Active'
            ],
            'Category' => $item->search_keyword,
            'ProductVariations' => !empty($item->product_variations) ? $item->product_variations : [
                [
                    'DigiKeyProductNumber' => $item->digikey_product_number
                ]
            ],
            'Parameters' => !empty($item->parameters) ? $item->parameters : ($raw['Parameters'] ?? $raw['ProductAttributes'] ?? []),
            'Classifications' => !empty($item->classifications) ? $item->classifications : ($raw['Classifications'] ?? null),
            'Series' => !empty($item->series) ? $item->series : ($raw['Series'] ?? null),
            'OtherNames' => !empty($item->other_names) ? $item->other_names : ($raw['OtherNames'] ?? []),
            'BaseProductNumber' => !empty($item->base_product_number) ? $item->base_product_number : ($raw['BaseProductNumber'] ?? null),
            'CategoryDetails' => !empty($item->category_details) ? $item->category_details : ($raw['Category'] ?? null),
            'DateLastBuyChance' => $item->date_last_buy_chance ?? ($raw['DateLastBuyChance'] ?? null),
            'ShippingInfo' => !empty($item->shipping_info) ? $item->shipping_info : ($raw['ShippingInfo'] ?? null),
            'RawResponse' => $raw,
        ]);

        return response()->json($response);
    }

    /**
     * Get all DigiKey categories and subcategories list with counts
     */
    public function categories()
    {
        $allCategories = \App\Models\DigiKeyCategory::where('parent_id', '!=', 0)
            ->select('name', 'product_count as count')
            ->orderBy('name', 'asc')
            ->get();

        if ($allCategories->isEmpty()) {
            $allCategories = DigiKeyProduct::selectRaw('search_keyword as name, COUNT(*) as count')
                ->whereNotNull('search_keyword')
                ->groupBy('search_keyword')
                ->orderBy('search_keyword', 'asc')
                ->get();
        }

        $dbCounts = DigiKeyProduct::selectRaw('search_keyword as name, COUNT(*) as count')
            ->whereNotNull('search_keyword')
            ->groupBy('search_keyword')
            ->pluck('count', 'name');

        $categories = $allCategories->map(function ($cat) use ($dbCounts) {
            $dbCount = $dbCounts[$cat->name] ?? 0;
            return [
                'name' => $cat->name,
                'count' => $dbCount > 0 ? (int)$dbCount : (int)$cat->count
            ];
        })->unique('name')->values();

        return response()->json([
            'Categories' => $categories
        ]);
    }


    /**
     * Trigger manual sync process via API endpoint
     */
    public function triggerSync(Request $request)
    {
        $keyword = $request->input('keyword');
        $count = $request->input('count', 20);

        $exitCode = Artisan::call('digikey:sync', [
            '--keyword' => $keyword,
            '--count' => $count,
        ]);

        return response()->json([
            'success' => $exitCode === 0,
            'message' => $exitCode === 0 ? 'DigiKey synchronization executed successfully.' : 'Sync failed.',
            'output' => Artisan::output(),
        ]);
    }
}
