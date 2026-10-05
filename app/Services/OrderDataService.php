<?php

namespace App\Services;

use App\Models\PcbOrder;
use App\Models\PcbOrderMeta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderDataService
{
    /**
     * Redundant meta keys that should not be stored in pcb_order_meta
     * because they have canonical columns in pcb_orders.
     */
    public const REDUNDANT_META_KEYS = [
        'order_number',
        'status',
        'status_id',
        'unit_price',
        'order_value',
        'gerber_file_id',
        'delivery_method',
    ];

    /**
     * Update canonical pcb_orders fields from an array of inputs.
     * Keeps final_qty and completed_qty synchronized.
     *
     * @param PcbOrder $order
     * @param array $inputs
     * @return array List of human-readable changes
     */
    public static function updateCanonicalOrderFields(PcbOrder $order, array $inputs): array
    {
        $changesLog = [];

        // 1. P/N Number
        if (array_key_exists('pn_number', $inputs)) {
            $newPn = trim((string)$inputs['pn_number']);
            $currentPn = (string)($order->pn_number ?? '');
            if ($currentPn !== $newPn) {
                $oldPn = $order->pn_number ?? 'N/A';
                $order->pn_number = $newPn !== '' ? $newPn : null;
                $changesLog[] = "P/N Number: '{$oldPn}' → '" . ($newPn !== '' ? $newPn : 'Empty') . "'";
            }
        }

        // 2. Order Quantity
        if (array_key_exists('order_qty', $inputs) || array_key_exists('quantity', $inputs) || array_key_exists('qty', $inputs)) {
            $rawQty = $inputs['order_qty'] ?? $inputs['quantity'] ?? $inputs['qty'];
            $newQty = max(1, (int)$rawQty);
            $oldQty = (int)($order->order_qty ?? 0);
            if ($newQty !== $oldQty) {
                $order->order_qty = $newQty;
                $changesLog[] = "Order Qty: {$oldQty} → {$newQty} Pcs";
            }
        }

        // 3. Layers
        if (array_key_exists('layers', $inputs) || array_key_exists('layer', $inputs)) {
            $rawLayers = $inputs['layers'] ?? $inputs['layer'];
            $newLayers = (int)$rawLayers;
            if ($newLayers > 0 && (int)$order->layers !== $newLayers) {
                $order->layers = $newLayers;
                $changesLog[] = "Layers: {$newLayers}";
            }
        }

        // 4. PCB Color / Mask
        if (array_key_exists('mask', $inputs) || array_key_exists('pcb_color', $inputs) || array_key_exists('solder_mask', $inputs)) {
            $rawMask = trim((string)($inputs['mask'] ?? $inputs['pcb_color'] ?? $inputs['solder_mask']));
            if ($rawMask !== '' && (string)$order->mask !== $rawMask) {
                $order->mask = $rawMask;
                $changesLog[] = "Mask Color: {$rawMask}";
            }
        }

        // 5. Production Quantities
        if (array_key_exists('launch_qty', $inputs)) {
            $newVal = (int)$inputs['launch_qty'];
            $oldVal = (int)($order->launch_qty ?? 0);
            if ($newVal !== $oldVal) {
                $order->launch_qty = $newVal;
                $changesLog[] = "Launch Qty: {$oldVal} → {$newVal} Pcs";
            }
        }

        if (array_key_exists('panel_qty', $inputs)) {
            $newVal = (int)$inputs['panel_qty'];
            $oldVal = (int)($order->panel_qty ?? 0);
            if ($newVal !== $oldVal) {
                $order->panel_qty = $newVal;
                $changesLog[] = "Panel Qty: {$oldVal} → {$newVal} Pcs";
            }
        }

        if (array_key_exists('ups_qty', $inputs)) {
            $newVal = (int)$inputs['ups_qty'];
            $oldVal = (int)($order->ups_qty ?? 0);
            if ($newVal !== $oldVal) {
                $order->ups_qty = $newVal;
                $changesLog[] = "Ups Qty: {$oldVal} → {$newVal} Pcs";
            }
        }

        // 6. Final Qty & Completed Qty (Synchronized alias)
        if (array_key_exists('final_qty', $inputs) || array_key_exists('completed_qty', $inputs)) {
            $newFinal = (int)($inputs['final_qty'] ?? $inputs['completed_qty']);
            $oldFinal = (int)($order->final_qty ?? $order->completed_qty ?? 0);
            if ($newFinal !== $oldFinal) {
                $order->final_qty = $newFinal;
                $order->completed_qty = $newFinal;
                $changesLog[] = "Final/Completed Qty: {$oldFinal} → {$newFinal} Pcs";
            }
        }

        if (array_key_exists('failed_qty', $inputs)) {
            $newVal = (int)$inputs['failed_qty'];
            $oldVal = (int)($order->failed_qty ?? 0);
            if ($newVal !== $oldVal) {
                $order->failed_qty = $newVal;
                $changesLog[] = "Failed Qty: {$oldVal} → {$newVal} Pcs";
            }
        }

        // 7. Unit Price & Order Value
        if (array_key_exists('unit_price', $inputs)) {
            $newUnit = floatval($inputs['unit_price']);
            $oldUnit = floatval($order->unit_price ?? 0);
            if (abs($newUnit - $oldUnit) > 0.001) {
                $order->unit_price = $newUnit;
                $changesLog[] = "Unit Price: ₹{$oldUnit} → ₹{$newUnit}";
            }
        }

        if (array_key_exists('order_value', $inputs)) {
            $newVal = floatval($inputs['order_value']);
            $oldVal = floatval($order->order_value ?? 0);
            if (abs($newVal - $oldVal) > 0.001) {
                $order->order_value = $newVal;
                $changesLog[] = "Order Value: ₹{$oldVal} → ₹{$newVal}";
            }
        }

        // 8. Delivery Date
        if (array_key_exists('delivery_date', $inputs)) {
            $rawDate = $inputs['delivery_date'];
            $normDate = (!empty($rawDate)) ? date('Y-m-d', strtotime($rawDate)) : null;
            $oldDate = !empty($order->delivery_date) ? date('Y-m-d', strtotime($order->delivery_date)) : null;
            if ($normDate !== $oldDate) {
                $order->delivery_date = $normDate;
                $changesLog[] = "Delivery Date: '{$oldDate}' → '{$normDate}'";
            }
        }

        // 9. Bill Number
        if (array_key_exists('bill_number', $inputs)) {
            $cleanBill = trim((string)$inputs['bill_number']);
            if ((string)($order->bill_number ?? '') !== $cleanBill) {
                $oldBill = $order->bill_number ?? 'N/A';
                $order->bill_number = $cleanBill !== '' ? $cleanBill : null;
                $changesLog[] = "Bill No: '{$oldBill}' → '{$cleanBill}'";
            }
        }

        // 10. Film Applied
        if (array_key_exists('film_applied', $inputs)) {
            $filmBool = filter_var($inputs['film_applied'], FILTER_VALIDATE_BOOLEAN) ||
                $inputs['film_applied'] == 1 || $inputs['film_applied'] === '1' || $inputs['film_applied'] === 'true';
            $filmVal = $filmBool ? 1 : 0;
            if ((int)($order->film_applied ?? 0) !== $filmVal) {
                $order->film_applied = $filmVal;
                $changesLog[] = "Film Applied: " . ($filmBool ? "Yes" : "No");
            }
        }

        return $changesLog;
    }

    /**
     * Clean redundant meta keys for a specific order.
     */
    public static function cleanupRedundantMeta(int $orderId, array $keys = null): int
    {
        $keys = $keys ?: self::REDUNDANT_META_KEYS;
        return PcbOrderMeta::where('pcb_order_id', $orderId)
            ->whereIn('meta_key', $keys)
            ->delete();
    }
}
