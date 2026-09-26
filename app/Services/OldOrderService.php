<?php

namespace App\Services;

use App\Models\PcbOrder;
use App\Models\PcbOrderOldOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OldOrderService
{
    /**
     * Parse old order string into array of normalized order numbers.
     * Supports '+', ',', whitespace separators.
     * Removes duplicates and empty values.
     */
    public static function parseOldOrderString(?string $oldStr): array
    {
        if (empty($oldStr) || trim($oldStr) === '') {
            return [];
        }

        $parts = preg_split('/[\+,\s]+/', trim($oldStr));
        $cleaned = [];
        foreach ($parts as $part) {
            $val = trim($part);
            if ($val !== '') {
                $cleaned[] = strtoupper($val);
            }
        }

        return array_values(array_unique($cleaned));
    }

    /**
     * Sync old order relationships for a given order given old order IDs or order numbers.
     * Removes obsolete relationships and adds new ones transactionally.
     * Prevents self-reference and duplicates.
     */
    public static function syncOldOrders(PcbOrder $currentOrder, array $oldOrderIdsOrNumbers, ?string &$error = null): bool
    {
        DB::beginTransaction();
        try {
            $targetOrderIds = [];

            foreach ($oldOrderIdsOrNumbers as $item) {
                if (is_numeric($item)) {
                    $id = (int)$item;
                    if ($id !== $currentOrder->id) {
                        $targetOrderIds[] = $id;
                    }
                } else {
                    $orderNo = trim((string)$item);
                    if ($orderNo === '') {
                        continue;
                    }

                    if (strcasecmp($orderNo, $currentOrder->order_number) === 0) {
                        // Skip self reference
                        continue;
                    }

                    $oldOrderRecord = PcbOrder::where('order_number', $orderNo)
                        ->orWhere('order_number', strtoupper($orderNo))
                        ->first();

                    if ($oldOrderRecord) {
                        if ($oldOrderRecord->id !== $currentOrder->id) {
                            $targetOrderIds[] = $oldOrderRecord->id;
                        }
                    } else {
                        $error = "Old order {$orderNo} was not found.";
                        DB::rollBack();
                        return false;
                    }
                }
            }

            $targetOrderIds = array_values(array_unique($targetOrderIds));

            // Prevent self-selection
            $targetOrderIds = array_filter($targetOrderIds, fn($id) => $id !== $currentOrder->id);

            // Remove old relationships for this order
            if (Schema::hasTable('pcb_order_old_orders')) {
                PcbOrderOldOrder::where('order_id', $currentOrder->id)->delete();
            }

            // Insert new relationships
            $oldNumbers = [];
            foreach ($targetOrderIds as $targetId) {
                if (Schema::hasTable('pcb_order_old_orders')) {
                    PcbOrderOldOrder::firstOrCreate([
                        'order_id'     => $currentOrder->id,
                        'old_order_id' => $targetId,
                    ]);
                }

                $child = PcbOrder::find($targetId);
                if ($child && $child->order_number) {
                    $oldNumbers[] = $child->order_number;
                }
            }

            // Update string column if column exists
            if (Schema::hasColumn('pcb_orders', 'old_order_number')) {
                $currentOrder->old_order_number = !empty($oldNumbers) ? implode(', ', $oldNumbers) : null;
                $currentOrder->save();
            }

            DB::commit();
            return true;
        } catch (\Throwable $e) {
            DB::rollBack();
            $error = $e->getMessage();
            return false;
        }
    }
}
