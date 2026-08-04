<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\InventoryLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InventoryController extends Controller
{
    private function seedDefaultsIfNeeded()
    {
        if (InventoryItem::count() === 0) {
            $defaults = [
                ['sku' => 'RES-10K-0603', 'name' => '10kΩ 0603 Resistor', 'unit_price' => 0.15, 'available_quantity' => 50000, 'low_stock_threshold' => 10000, 'status' => 'In Stock'],
                ['sku' => 'CAP-100N-0402', 'name' => '100nF 0402 Capacitor', 'unit_price' => 0.20, 'available_quantity' => 8500, 'low_stock_threshold' => 10000, 'status' => 'Low Stock'],
                ['sku' => 'IC-ESP32-WR', 'name' => 'ESP32-WROOM-32D', 'unit_price' => 285.00, 'available_quantity' => 450, 'low_stock_threshold' => 500, 'status' => 'Low Stock'],
                ['sku' => 'IC-STM32-F1', 'name' => 'STM32F103C8T6', 'unit_price' => 145.50, 'available_quantity' => 1200, 'low_stock_threshold' => 300, 'status' => 'In Stock'],
                ['sku' => 'IC-LM317-SOT', 'name' => 'LM317 Voltage Regulator', 'unit_price' => 12.00, 'available_quantity' => 8500, 'low_stock_threshold' => 2000, 'status' => 'In Stock'],
                ['sku' => 'CON-2X20-F', 'name' => '2x20 Pin Header Female', 'unit_price' => 18.50, 'available_quantity' => 120, 'low_stock_threshold' => 500, 'status' => 'Low Stock'],
                ['sku' => 'CON-USBC-SMD', 'name' => 'USB Type-C Connector', 'unit_price' => 22.00, 'available_quantity' => 3400, 'low_stock_threshold' => 1000, 'status' => 'In Stock'],
                ['sku' => 'CON-MUSB-SMD', 'name' => 'Micro USB Connector', 'unit_price' => 15.00, 'available_quantity' => 0, 'low_stock_threshold' => 500, 'status' => 'Out of Stock'],
                ['sku' => 'IC-ATM328P', 'name' => 'ATmega328P-AU', 'unit_price' => 185.00, 'available_quantity' => 800, 'low_stock_threshold' => 200, 'status' => 'In Stock'],
                ['sku' => 'IC-NE555-DIP', 'name' => 'NE555 Linear Regulator', 'unit_price' => 8.50, 'available_quantity' => 15000, 'low_stock_threshold' => 5000, 'status' => 'In Stock'],
                ['sku' => 'CAP-1U-0805', 'name' => '1uF 0805 Capacitor', 'unit_price' => 0.25, 'available_quantity' => 22000, 'low_stock_threshold' => 5000, 'status' => 'In Stock'],
                ['sku' => 'RES-4K7-0805', 'name' => '4.7kΩ 0805 Resistor', 'unit_price' => 0.18, 'available_quantity' => 18000, 'low_stock_threshold' => 5000, 'status' => 'In Stock'],
                ['sku' => 'IC-BSS138-SOT', 'name' => 'BSS138 Timer IC', 'unit_price' => 5.00, 'available_quantity' => 4200, 'low_stock_threshold' => 1000, 'status' => 'In Stock'],
                ['sku' => 'LED-WS2812B', 'name' => 'WS2812B RGB LED', 'unit_price' => 8.00, 'available_quantity' => 6500, 'low_stock_threshold' => 2000, 'status' => 'In Stock'],
                ['sku' => 'IC-CH340G', 'name' => 'CH340G USB to Serial', 'unit_price' => 45.00, 'available_quantity' => 150, 'low_stock_threshold' => 500, 'status' => 'Low Stock'],
                ['sku' => 'SW-TACT-6X6', 'name' => 'Tactile Push Button 6x6', 'unit_price' => 2.50, 'available_quantity' => 12000, 'low_stock_threshold' => 3000, 'status' => 'In Stock'],
                ['sku' => 'SW-SLIDE-SPDT', 'name' => 'Slide Switch SPDT', 'unit_price' => 4.00, 'available_quantity' => 4500, 'low_stock_threshold' => 1000, 'status' => 'In Stock'],
                ['sku' => 'DIO-1N4148-SMD', 'name' => '1N4148 Diode', 'unit_price' => 0.80, 'available_quantity' => 28000, 'low_stock_threshold' => 8000, 'status' => 'In Stock'],
                ['sku' => 'FET-SS34-SMA', 'name' => 'SS34 MOSFET', 'unit_price' => 3.50, 'available_quantity' => 9000, 'low_stock_threshold' => 2000, 'status' => 'In Stock'],
                ['sku' => 'HLD-CR2032-SMD', 'name' => 'CR2032 Battery Holder', 'unit_price' => 14.00, 'available_quantity' => 80, 'low_stock_threshold' => 300, 'status' => 'Low Stock'],
            ];

            foreach ($defaults as $item) {
                InventoryItem::create($item);
            }
        }
    }

    private function calculateStatus($qty, $threshold)
    {
        if ($qty == 0) return 'Out of Stock';
        if ($qty <= $threshold) return 'Low Stock';
        return 'In Stock';
    }

    public function index(Request $request)
    {
        $this->seedDefaultsIfNeeded();

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

        if ($request->has('name')) $item->name = $request->name;
        if ($request->has('sku')) $item->sku = strtoupper($request->sku);
        if ($request->has('unit_price')) $item->unit_price = $request->unit_price;
        if ($request->has('available_quantity')) $item->available_quantity = (int)$request->available_quantity;
        if ($request->has('low_stock_threshold')) $item->low_stock_threshold = (int)$request->low_stock_threshold;

        $item->status = $this->calculateStatus($item->available_quantity, $item->low_stock_threshold);
        $item->save();

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
