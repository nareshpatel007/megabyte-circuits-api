<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobileInventoryController extends Controller
{
    private function checkPermission(Request $request)
    {
        $adminId = $request->attributes->get('admin_id');
        $admin = null;
        if ($adminId) {
            $admin = DB::table('admins')->where('id', $adminId)->first() ?: DB::table('users')->where('id', $adminId)->first();
        }
        $permissions = $admin ? MobileAuthController::fetchPermissionsForAdmin($admin) : ['orders.view', 'inventory.view'];

        if (!in_array('inventory.view', $permissions)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access Inventory.'
            ], 403);
        }

        return null;
    }

    public function index(Request $request)
    {
        if ($forbidden = $this->checkPermission($request)) {
            return $forbidden;
        }

        try {
            $search = trim($request->input('search', ''));
            $statusFilter = trim(strtolower($request->input('status', 'all')));
            $page = max(1, (int)$request->input('page', 1));
            $perPage = min(100, max(5, (int)$request->input('per_page', 20)));

            $query = DB::table('inventory_items')->whereNull('deleted_at');

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('sku', 'LIKE', "%{$search}%")
                      ->orWhere('name', 'LIKE', "%{$search}%");
                });
            }

            if ($statusFilter === 'in_stock') {
                $query->whereRaw('available_quantity > low_stock_threshold');
            } elseif ($statusFilter === 'low_stock') {
                $query->whereRaw('available_quantity > 0 AND available_quantity <= low_stock_threshold');
            } elseif ($statusFilter === 'out_of_stock') {
                $query->where('available_quantity', '=', 0);
            }

            $total = $query->count();
            $items = $query->orderBy('id', 'asc')
                ->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            // Calculate overall inventory summary counts
            $allQuery = DB::table('inventory_items')->whereNull('deleted_at');
            $componentsCount = $allQuery->count();
            $lowStockCount = DB::table('inventory_items')->whereNull('deleted_at')->whereRaw('available_quantity > 0 AND available_quantity <= low_stock_threshold')->count();
            $outOfStockCount = DB::table('inventory_items')->whereNull('deleted_at')->where('available_quantity', '=', 0)->count();

            $formattedItems = $items->map(function ($item) {
                $qty = (int) ($item->available_quantity ?? $item->quantity ?? 0);
                $threshold = (int) ($item->low_stock_threshold ?? $item->threshold ?? 50);

                $statusStr = 'In stock';
                if ($qty === 0) {
                    $statusStr = 'Out of stock';
                } elseif ($qty <= $threshold) {
                    $statusStr = 'Low stock';
                }

                return [
                    'id' => (string) $item->id,
                    'sku' => $item->sku ?? ('SKU-' . $item->id),
                    'name' => $item->name ?? 'Component',
                    'category' => $item->category ?? 'Passive',
                    'supplier' => $item->supplier ?? 'Vendor',
                    'quantity' => $qty,
                    'threshold' => $threshold,
                    'maxStock' => (int) ($item->max_stock ?? ($threshold * 4)),
                    'unitPrice' => (float) ($item->unit_price ?? 0),
                    'location' => $item->location ?? 'Rack A-01',
                    'status' => $statusStr,
                    'lastUpdated' => ($item->updated_at ?? null) ? date('d M Y', strtotime($item->updated_at)) : date('d M Y')
                ];
            });

            return response()->json([
                'success' => true,
                'summary' => [
                    'components' => $componentsCount,
                    'low_stock' => $lowStockCount,
                    'out_of_stock' => $outOfStockCount
                ],
                'data' => $formattedItems,
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => max(1, (int)ceil($total / $perPage))
                ]
            ]);

        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        if ($forbidden = $this->checkPermission($request)) {
            return $forbidden;
        }

        try {
            $item = DB::table('inventory_items')
                ->whereNull('deleted_at')
                ->where('id', $id)
                ->orWhere('sku', $id)
                ->first();

            if (!$item) {
                return response()->json([
                    'success' => false,
                    'message' => 'Inventory item not found'
                ], 404);
            }

            $qty = (int) ($item->available_quantity ?? $item->quantity ?? 0);
            $threshold = (int) ($item->low_stock_threshold ?? $item->threshold ?? 50);

            $statusStr = 'In stock';
            if ($qty === 0) {
                $statusStr = 'Out of stock';
            } elseif ($qty <= $threshold) {
                $statusStr = 'Low stock';
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => (string) $item->id,
                    'sku' => $item->sku ?? ('SKU-' . $item->id),
                    'name' => $item->name ?? 'Component',
                    'category' => $item->category ?? 'Passive',
                    'supplier' => $item->supplier ?? 'Vendor',
                    'quantity' => $qty,
                    'threshold' => $threshold,
                    'maxStock' => (int) ($item->max_stock ?? ($threshold * 4)),
                    'unitPrice' => (float) ($item->unit_price ?? 0),
                    'location' => $item->location ?? 'Rack A-01',
                    'status' => $statusStr,
                    'lastUpdated' => ($item->updated_at ?? null) ? date('d M Y', strtotime($item->updated_at)) : date('d M Y')
                ]
            ]);

        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function adjustStock(Request $request, $id)
    {
        if ($forbidden = $this->checkPermission($request)) {
            return $forbidden;
        }

        try {
            $amount = (int) $request->input('amount', 0);
            $item = DB::table('inventory_items')->where('id', $id)->first();

            if (!$item) {
                return response()->json(['success' => false, 'message' => 'Item not found'], 404);
            }

            $newQty = max(0, ((int)$item->available_quantity) + $amount);

            DB::table('inventory_items')->where('id', $id)->update([
                'available_quantity' => $newQty,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Stock adjusted successfully',
                'new_quantity' => $newQty
            ]);

        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }
}
