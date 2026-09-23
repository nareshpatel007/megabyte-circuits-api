<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\InventoryLog;
use App\Jobs\SendInventoryTemplateEmailJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InventoryController extends Controller
{
    private function calculateStatus($qty, $threshold)
    {
        if ($qty == 0) return 'Out of Stock';
        if ($qty <= $threshold) return 'Low Stock';
        return 'In Stock';
    }

    public function index(Request $request)
    {
        $query = InventoryItem::query();

        if ($request->has('search') && !empty($request->search)) {
            $s = strtolower($request->search);
            $query->where(function ($q) use ($s) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$s}%"])
                    ->orWhereRaw('LOWER(sku) LIKE ?', ["%{$s}%"]);
            });
        }

        if ($request->has('status') && $request->status !== 'All') {
            $query->where('status', $request->status);
        }

        $items = $query->orderBy('id', 'desc')->get();

        return response()->json([
            'status' => true,
            'success' => true,
            'data' => $items,
        ]);
    }

    public function show($id)
    {
        $item = InventoryItem::where('id', $id)->orWhere('sku', $id)->first();
        if (!$item) {
            return response()->json([
                'status' => false,
                'message' => 'Component not found',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'success' => true,
            'data' => $item,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'sku' => 'required|string|max:100|unique:inventory_items,sku',
            'unit_price' => 'required|numeric|min:0',
            'available_quantity' => 'required|integer|min:0',
            'low_stock_threshold' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $qty = (int)$request->available_quantity;
        $threshold = (int)$request->low_stock_threshold;
        $status = $this->calculateStatus($qty, $threshold);

        $item = InventoryItem::create([
            'name' => $request->name,
            'sku' => strtoupper($request->sku),
            'unit_price' => $request->unit_price,
            'available_quantity' => $qty,
            'low_stock_threshold' => $threshold,
            'status' => $status,
        ]);

        // Evaluate initial stock alerts
        if ($qty <= 0) {
            \App\Services\EmailTemplateService::sendInventoryEmail('inventory_out_of_stock', $item->id);
        } elseif ($qty <= $threshold) {
            \App\Services\EmailTemplateService::sendInventoryEmail('inventory_low_stock', $item->id);
        }

        return response()->json([
            'status' => true,
            'success' => true,
            'message' => 'Component added successfully',
            'data' => $item,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $item = InventoryItem::find($id);
        if (!$item) {
            return response()->json([
                'status' => false,
                'message' => 'Component not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'sku' => 'sometimes|required|string|max:100|unique:inventory_items,sku,' . $id,
            'unit_price' => 'sometimes|required|numeric|min:0',
            'available_quantity' => 'sometimes|required|integer|min:0',
            'low_stock_threshold' => 'sometimes|required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $prevQty = (int)$item->available_quantity;
        $qtyChanged = $request->has('available_quantity') && ((int)$request->available_quantity !== $prevQty);

        if ($request->has('name')) $item->name = $request->name;
        if ($request->has('sku')) $item->sku = strtoupper($request->sku);
        if ($request->has('unit_price')) $item->unit_price = $request->unit_price;
        if ($request->has('available_quantity')) $item->available_quantity = (int)$request->available_quantity;
        if ($request->has('low_stock_threshold')) $item->low_stock_threshold = (int)$request->low_stock_threshold;

        $newQty = (int)$item->available_quantity;
        $item->status = $this->calculateStatus($newQty, $item->low_stock_threshold);
        $item->save();

        if ($qtyChanged) {
            $opType = ($newQty > $prevQty) ? 'in' : 'adjusted';
            $this->triggerInventoryEmailAlerts($item, $prevQty, $newQty, null, $opType);
        }

        return response()->json([
            'status' => true,
            'success' => true,
            'message' => 'Component updated successfully',
            'data' => $item,
        ]);
    }

    public function destroy($id)
    {
        $item = InventoryItem::find($id);
        if (!$item) {
            return response()->json([
                'status' => false,
                'message' => 'Component not found',
            ], 404);
        }

        $item->delete();

        return response()->json([
            'status' => true,
            'success' => true,
            'message' => 'Component deleted successfully',
        ]);
    }

    private function triggerInventoryEmailAlerts(InventoryItem $item, int $prevQty, int $newQty, ?InventoryLog $log = null, ?string $operationType = null)
    {
        $threshold = (int)$item->low_stock_threshold;

        // 1. Stock Added email
        if ($operationType === 'in') {
            \App\Services\EmailTemplateService::sendInventoryEmail('inventory_stock_added', $item->id, $log ? $log->id : null);
        }

        // 2. Stock Adjusted email (for manual adjustment out/update)
        if ($operationType === 'out' || $operationType === 'adjusted') {
            \App\Services\EmailTemplateService::sendInventoryEmail('inventory_stock_adjusted', $item->id, $log ? $log->id : null);
        }

        // 3. Low Stock Alert transition check (crosses threshold downwards)
        if ($prevQty > $threshold && $newQty <= $threshold && $newQty > 0) {
            \App\Services\EmailTemplateService::sendInventoryEmail('inventory_low_stock', $item->id, $log ? $log->id : null);
        }

        // 4. Out of Stock Alert transition check (available stock reaches 0)
        if ($prevQty > 0 && $newQty <= 0) {
            \App\Services\EmailTemplateService::sendInventoryEmail('inventory_out_of_stock', $item->id, $log ? $log->id : null);
        }
    }

    public function adjustStock(Request $request, $id)
    {
        $item = InventoryItem::find($id);
        if (!$item) {
            return response()->json([
                'status' => false,
                'message' => 'Component not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:in,out',
            'quantity' => 'required|integer|min:1',
            'note' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $type = strtolower($request->type);
        $qty = (int)$request->quantity;
        $prevQty = (int)$item->available_quantity;

        if ($type === 'out' && $qty > $prevQty) {
            return response()->json([
                'status' => false,
                'message' => "Cannot remove {$qty} units. Only {$prevQty} available in stock.",
            ], 422);
        }

        $newQty = ($type === 'in') ? ($prevQty + $qty) : ($prevQty - $qty);

        $item->available_quantity = $newQty;
        $item->status = $this->calculateStatus($newQty, $item->low_stock_threshold);
        $item->save();

        $log = InventoryLog::create([
            'inventory_item_id' => $item->id,
            'type' => $type,
            'quantity' => $qty,
            'previous_quantity' => $prevQty,
            'new_quantity' => $newQty,
            'note' => $request->note ? trim($request->note) : null,
            'created_by' => 'Admin Administrator',
        ]);

        $this->triggerInventoryEmailAlerts($item, $prevQty, $newQty, $log, $type);

        return response()->json([
            'status' => true,
            'success' => true,
            'message' => "Stock successfully updated (" . strtoupper($type) . " +{$qty})",
            'data' => [
                'item' => $item,
                'log' => $log,
            ]
        ]);
    }

    public function getLogs($id)
    {
        $item = InventoryItem::find($id);
        if (!$item) {
            return response()->json([
                'status' => false,
                'message' => 'Component not found',
            ], 404);
        }

        $logs = InventoryLog::where('inventory_item_id', $id)
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'success' => true,
            'data' => [
                'item' => $item,
                'logs' => $logs,
            ]
        ]);
    }
}
