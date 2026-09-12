<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DigiKeyProduct;
use App\Models\DigiKeyProductMargin;
use App\Services\DigiKeyPricingService;

class AdminDigiKeyProductsController extends Controller
{
    /**
     * Get paginated DigiKey products with margin & price details for Admin UI
     */
    public function index(Request $request)
    {
        $keyword = trim($request->query('keyword') ?? $request->query('search') ?? '');
        $category = trim($request->query('category') ?? '');
        $page = max(1, (int) $request->query('page', 1));
        $limit = max(1, min((int) ($request->query('per_page') ?? $request->query('limit') ?? 15), 100));
        $offset = ($page - 1) * $limit;

        $query = DigiKeyProduct::with('margin');

        if (!empty($category) && strtolower($category) !== 'all') {
            $query->where('search_keyword', 'like', "%{$category}%");
        }

        if (!empty($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('digikey_product_number', 'like', "%{$keyword}%")
                  ->orWhere('manufacturer_product_number', 'like', "%{$keyword}%")
                  ->orWhere('product_description', 'like', "%{$keyword}%")
                  ->orWhere('detailed_description', 'like', "%{$keyword}%")
                  ->orWhere('manufacturer_name', 'like', "%{$keyword}%");
            });
        }

        $totalCount = $query->count();
        $products = $query->orderBy('id', 'asc')->skip($offset)->take($limit)->get();

        $defaultMargin = DigiKeyPricingService::getDefaultMargin();

        $formatted = $products->map(function ($item) use ($defaultMargin) {
            $productMargin = $item->margin;
            $hasCustom = $productMargin && $productMargin->is_active;

            $marginType = $hasCustom ? $productMargin->margin_type : $defaultMargin['margin_type'];
            $marginValue = $hasCustom ? (float) $productMargin->margin_value : (float) $defaultMargin['margin_value'];

            $marginConfig = [
                'margin_type' => $marginType,
                'margin_value' => $marginValue,
                'is_custom' => $hasCustom,
            ];

            $pricing = DigiKeyPricingService::calculateCustomerPricing($item, 1, $marginConfig);

            return [
                'id' => $item->id,
                'digikey_product_number' => $item->digikey_product_number,
                'manufacturer_product_number' => $item->manufacturer_product_number,
                'manufacturer_name' => $item->manufacturer_name,
                'product_description' => $item->product_description,
                'category' => $item->search_keyword,
                'base_unit_price' => (float) $item->unit_price,
                'margin_type' => $marginType,
                'margin_value' => $marginValue,
                'is_custom_margin' => $hasCustom,
                'final_customer_price' => $pricing['unit_price'],
                'quantity_available' => (int) $item->quantity_available,
                'product_status' => $item->product_status ?? 'Active',
                'pricing_tiers' => $pricing['tiers'],
                'updated_at' => $item->updated_at ? $item->updated_at->toDateTimeString() : null,
            ];
        });

        $totalPages = ceil($totalCount / ($limit > 0 ? $limit : 1));

        return response()->json([
            'success' => true,
            'data' => $formatted,
            'meta' => [
                'total' => $totalCount,
                'page' => $page,
                'per_page' => $limit,
                'last_page' => $totalPages,
            ],
            'default_margin' => $defaultMargin,
        ]);
    }

    /**
     * Update product margin settings for a single product
     */
    public function updateMargin(Request $request, $id)
    {
        $product = DigiKeyProduct::find($id);
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        $validated = $request->validate([
            'margin_type' => 'required|in:percentage,fixed',
            'margin_value' => 'required|numeric|min:0',
        ]);

        $margin = DigiKeyProductMargin::updateOrCreate(
            ['digikey_product_id' => $product->id],
            [
                'margin_type' => $validated['margin_type'],
                'margin_value' => $validated['margin_value'],
                'is_active' => true,
            ]
        );

        $pricing = DigiKeyPricingService::calculateCustomerPricing($product);

        return response()->json([
            'success' => true,
            'message' => 'Product margin updated successfully',
            'data' => [
                'id' => $product->id,
                'margin_type' => $margin->margin_type,
                'margin_value' => (float) $margin->margin_value,
                'final_customer_price' => $pricing['unit_price'],
                'pricing_tiers' => $pricing['tiers'],
            ],
        ]);
    }

    /**
     * Reset product margin to global default
     */
    public function resetMargin($id)
    {
        $product = DigiKeyProduct::find($id);
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        DigiKeyProductMargin::where('digikey_product_id', $product->id)->delete();

        $pricing = DigiKeyPricingService::calculateCustomerPricing($product);

        return response()->json([
            'success' => true,
            'message' => 'Product margin reset to global default successfully',
            'data' => [
                'id' => $product->id,
                'margin_type' => $pricing['margin']['type'],
                'margin_value' => $pricing['margin']['value'],
                'is_custom_margin' => false,
                'final_customer_price' => $pricing['unit_price'],
                'pricing_tiers' => $pricing['tiers'],
            ],
        ]);
    }

    /**
     * Bulk update margins for multiple products
     */
    public function bulkUpdateMargins(Request $request)
    {
        $validated = $request->validate([
            'product_ids' => 'required|array',
            'product_ids.*' => 'integer|exists:digikey_products,id',
            'margin_type' => 'required|in:percentage,fixed',
            'margin_value' => 'required|numeric|min:0',
        ]);

        foreach ($validated['product_ids'] as $productId) {
            DigiKeyProductMargin::updateOrCreate(
                ['digikey_product_id' => $productId],
                [
                    'margin_type' => $validated['margin_type'],
                    'margin_value' => $validated['margin_value'],
                    'is_active' => true,
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Bulk margin updated successfully for ' . count($validated['product_ids']) . ' products',
        ]);
    }

    /**
     * Get & Update Global Default DigiKey Margin
     */
    public function getDefaultMargin()
    {
        return response()->json([
            'success' => true,
            'data' => DigiKeyPricingService::getDefaultMargin(),
        ]);
    }

    public function updateDefaultMargin(Request $request)
    {
        $validated = $request->validate([
            'margin_type' => 'required|in:percentage,fixed',
            'margin_value' => 'required|numeric|min:0',
        ]);

        $defaultMargin = DigiKeyProductMargin::updateOrCreate(
            ['digikey_product_id' => null],
            [
                'margin_type' => $validated['margin_type'],
                'margin_value' => $validated['margin_value'],
                'is_active' => true,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Default DigiKey margin updated successfully',
            'data' => [
                'margin_type' => $defaultMargin->margin_type,
                'margin_value' => (float) $defaultMargin->margin_value,
                'is_active' => (bool) $defaultMargin->is_active,
            ],
        ]);
    }
}
