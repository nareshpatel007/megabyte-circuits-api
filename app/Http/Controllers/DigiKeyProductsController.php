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
                $q->where('digikey_product_number', 'like', "%{$keyword}%")
                  ->orWhere('manufacturer_product_number', 'like', "%{$keyword}%")
                  ->orWhere('manufacturer_name', 'like', "%{$keyword}%")
                  ->orWhere('search_keyword', 'like', "%{$keyword}%")
                  ->orWhere('product_description', 'like', "%{$keyword}%")
                  ->orWhere('detailed_description', 'like', "%{$keyword}%");
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

        // Get active categories list (only categories that actually have products in local DB)
        $categories = $this->getActiveLocalCategories();

        // Map format for frontend standard with dynamic customer pricing
        $formatted = $products->map(function ($item) {
            return \App\Services\DigiKeyPricingService::formatProductResponse($item);
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

        $formatted = \App\Services\DigiKeyPricingService::formatProductResponse($item);
        $raw = is_array($item->raw_response) ? $item->raw_response : json_decode($item->raw_response ?? '{}', true);

        // Merge DB columns with DigiKey format keys for full frontend compatibility
        $response = array_merge($item->toArray(), $formatted, [
            'RawResponse' => $raw,
        ]);

        return response()->json($response);
    }

    /**
     * Get all DigiKey categories and subcategories list with counts
     */
    public function categories()
    {
        $categories = $this->getActiveLocalCategories();

        return response()->json([
            'Categories' => $categories
        ]);
    }

    /**
     * Helper to return only categories that actually have products in local DB
     */
    private function getActiveLocalCategories()
    {
        $dbCountsByName = DigiKeyProduct::selectRaw('search_keyword as name, COUNT(*) as count')
            ->whereNotNull('search_keyword')
            ->where('search_keyword', '!=', '')
            ->groupBy('search_keyword')
            ->pluck('count', 'name');

        $dbCountsByCatId = DigiKeyProduct::selectRaw('category_id, COUNT(*) as count')
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->pluck('count', 'category_id');

        $allCategories = \App\Models\DigiKeyCategory::where('parent_id', '!=', 0)
            ->select('category_id', 'name')
            ->orderBy('name', 'asc')
            ->get();

        $categories = $allCategories->filter(function ($cat) use ($dbCountsByName, $dbCountsByCatId) {
            $countByName = $dbCountsByName[$cat->name] ?? 0;
            $countById = $dbCountsByCatId[$cat->category_id] ?? 0;
            return ($countByName > 0 || $countById > 0);
        })->map(function ($cat) use ($dbCountsByName, $dbCountsByCatId) {
            $countByName = $dbCountsByName[$cat->name] ?? 0;
            $countById = $dbCountsByCatId[$cat->category_id] ?? 0;
            $count = $countByName > 0 ? $countByName : $countById;
            return [
                'name' => $cat->name,
                'count' => (int) $count
            ];
        })->unique('name')->values();

        if ($categories->isEmpty()) {
            $categories = DigiKeyProduct::selectRaw('search_keyword as name, COUNT(*) as count')
                ->whereNotNull('search_keyword')
                ->where('search_keyword', '!=', '')
                ->groupBy('search_keyword')
                ->orderBy('search_keyword', 'asc')
                ->get()
                ->map(function ($cat) {
                    return [
                        'name' => $cat->name,
                        'count' => (int) $cat->count
                    ];
                })->values();
        }

        return $categories;
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
