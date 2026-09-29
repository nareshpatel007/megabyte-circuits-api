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

    public function store(Request $request)
    {
        $adminId = $request->attributes->get('admin_id');
        $admin = $adminId ? (DB::table('admins')->where('id', $adminId)->first() ?: DB::table('users')->where('id', $adminId)->first()) : null;
        $permissions = $admin ? MobileAuthController::fetchPermissionsForAdmin($admin) : [];

        if (!in_array('*', $permissions) && !in_array('inventory.create', $permissions) && !in_array('inventory.manage', $permissions)) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to create inventory items.'], 403);
        }

        try {
            $name = trim($request->input('name', ''));
            if (empty($name)) {
                return response()->json(['success' => false, 'message' => 'Item name is required.'], 422);
            }

            $sku = trim($request->input('sku', 'SKU-' . time()));
            $category = trim($request->input('category', 'General'));
            $supplier = trim($request->input('supplier', 'Default Vendor'));
            $qty = (int) $request->input('quantity', 0);
            $threshold = (int) $request->input('threshold', 50);
            $maxStock = (int) $request->input('maxStock', $threshold * 4);
            $unitPrice = (float) $request->input('unitPrice', 0.0);
            $location = trim($request->input('location', 'Warehouse A'));

            $id = DB::table('inventory_items')->insertGetId([
                'sku' => $sku,
                'name' => $name,
                'category' => $category,
                'supplier' => $supplier,
                'available_quantity' => $qty,
                'low_stock_threshold' => $threshold,
                'max_stock' => $maxStock,
                'unit_price' => $unitPrice,
                'location' => $location,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Inventory item created successfully.',
                'data' => [
                    'id' => (string)$id,
                    'sku' => $sku,
                    'name' => $name,
                    'category' => $category,
                    'supplier' => $supplier,
                    'quantity' => $qty,
                    'threshold' => $threshold,
                    'maxStock' => $maxStock,
                    'unitPrice' => $unitPrice,
                    'location' => $location,
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $adminId = $request->attributes->get('admin_id');
        $admin = $adminId ? (DB::table('admins')->where('id', $adminId)->first() ?: DB::table('users')->where('id', $adminId)->first()) : null;
        $permissions = $admin ? MobileAuthController::fetchPermissionsForAdmin($admin) : [];

        if (!in_array('*', $permissions) && !in_array('inventory.edit', $permissions) && !in_array('inventory.manage', $permissions)) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to edit inventory items.'], 403);
        }

        try {
            $item = DB::table('inventory_items')->where('id', $id)->first();
            if (!$item) {
                return response()->json(['success' => false, 'message' => 'Inventory item not found.'], 404);
            }

            $updateData = ['updated_at' => date('Y-m-d H:i:s')];
            if ($request->has('name')) $updateData['name'] = trim($request->input('name'));
            if ($request->has('sku')) $updateData['sku'] = trim($request->input('sku'));
            if ($request->has('category')) $updateData['category'] = trim($request->input('category'));
            if ($request->has('supplier')) $updateData['supplier'] = trim($request->input('supplier'));
            if ($request->has('quantity')) $updateData['available_quantity'] = (int)$request->input('quantity');
            if ($request->has('threshold')) $updateData['low_stock_threshold'] = (int)$request->input('threshold');
            if ($request->has('unitPrice')) $updateData['unit_price'] = (float)$request->input('unitPrice');
            if ($request->has('location')) $updateData['location'] = trim($request->input('location'));

            DB::table('inventory_items')->where('id', $id)->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Inventory item updated successfully.'
            ]);
        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $adminId = $request->attributes->get('admin_id');
        $admin = $adminId ? (DB::table('admins')->where('id', $adminId)->first() ?: DB::table('users')->where('id', $adminId)->first()) : null;
        $permissions = $admin ? MobileAuthController::fetchPermissionsForAdmin($admin) : [];

        if (!in_array('*', $permissions) && !in_array('inventory.delete', $permissions) && !in_array('inventory.manage', $permissions)) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to delete inventory items.'], 403);
        }

        try {
            $item = DB::table('inventory_items')->where('id', $id)->first();
            if (!$item) {
                return response()->json(['success' => false, 'message' => 'Inventory item not found.'], 404);
            }

            DB::table('inventory_items')->where('id', $id)->update(['deleted_at' => date('Y-m-d H:i:s')]);

            return response()->json([
                'success' => true,
                'message' => 'Inventory item deleted successfully.'
            ]);
        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()], 500);
        }
    }
}
