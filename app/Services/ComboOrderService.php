<?php

namespace App\Services;

use App\Models\PcbOrder;
use App\Models\PcbOrderCombo;
use App\Models\PcbOrderStatusHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ComboOrderService
{
    /**
     * Parse combo string into array of normalized order numbers.
     * Supports '+', ',', whitespace separators.
     * Removes duplicates and empty values.
     */
    public static function parseComboString(?string $comboStr): array
    {
        if (empty($comboStr) || trim($comboStr) === '') {
            return [];
        }

        $parts = preg_split('/[\+,\s]+/', trim($comboStr));
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
     * Sync combo relationships for a parent order given combo order IDs or combo order numbers.
     * Removes obsolete relationships and adds new ones.
     * Prevents self-reference and circular relationships.
     */
    public static function syncComboOrders(PcbOrder $parentOrder, array $comboOrderIdsOrNumbers, ?string &$error = null): bool
    {
        DB::beginTransaction();
        try {
            $targetOrderIds = [];

            foreach ($comboOrderIdsOrNumbers as $item) {
                if (is_numeric($item)) {
                    $id = (int)$item;
                    if ($id !== $parentOrder->id) {
                        $targetOrderIds[] = $id;
                    }
                } else {
                    $orderNo = trim((string)$item);
                    if ($orderNo === '') {
                        continue;
                    }

                    if (strcasecmp($orderNo, $parentOrder->order_number) === 0) {
                        // Skip self reference
                        continue;
                    }

                    $childOrder = PcbOrder::where('order_number', $orderNo)
                        ->orWhere('order_number', strtoupper($orderNo))
                        ->first();

                    if ($childOrder) {
                        if ($childOrder->id !== $parentOrder->id) {
                            $targetOrderIds[] = $childOrder->id;
                        }
                    } else {
                        // If combo order is not found, ignore combo adding and continue
                        continue;
                    }
                }
            }

            $targetOrderIds = array_values(array_unique($targetOrderIds));

            // Prevent target order from being a parent of this order (circular relationship check)
            foreach ($targetOrderIds as $targetId) {
                $isTargetAParentOfCurrent = PcbOrderCombo::where('parent_order_id', $targetId)
                    ->where('combo_order_id', $parentOrder->id)
                    ->exists();

                if ($isTargetAParentOfCurrent) {
                    $error = "Circular combo relationship detected for order ID {$targetId}.";
                    DB::rollBack();
                    return false;
                }
            }

            // Remove old relationships for this parent order (hard delete to prevent zombie duplicates)
            if (Schema::hasTable('pcb_order_combos')) {
                if (Schema::hasColumn('pcb_order_combos', 'deleted_at')) {
                    PcbOrderCombo::withTrashed()->where('parent_order_id', $parentOrder->id)->forceDelete();
                } else {
                    PcbOrderCombo::where('parent_order_id', $parentOrder->id)->delete();
                }
            }

            // Insert new relationships
            $comboNumbers = [];
            foreach ($targetOrderIds as $targetId) {
                PcbOrderCombo::create([
                    'parent_order_id' => $parentOrder->id,
                    'combo_order_id'  => $targetId,
                ]);

                $child = PcbOrder::find($targetId);
                if ($child && $child->order_number) {
                    $comboNumbers[] = $child->order_number;
                }
            }

            // Update parent string combo column for backwards compatibility
            $comboNumbers = array_values(array_unique($comboNumbers));
            $parentOrder->combo = !empty($comboNumbers) ? implode(', ', $comboNumbers) : null;
            $parentOrder->save();

            DB::commit();
            return true;
        } catch (\Throwable $e) {
            DB::rollBack();
            $error = $e->getMessage();
            return false;
        }
    }

    /**
     * Synchronize status of parent order to all its child combo orders.
     * Creates status history logs and audit entries.
     */
    public static function syncComboStatus(PcbOrder $parentOrder, $newStatusOrStatusId = null, ?int $adminId = null, string $updaterName = 'Operator'): void
    {
        if (!Schema::hasTable('pcb_order_combos')) {
            return;
        }

        $comboMemberIds = PcbOrderCombo::where('parent_order_id', $parentOrder->id)
            ->pluck('combo_order_id')
            ->toArray();

        if (empty($comboMemberIds)) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        // Resolve canonical status
        $canonicalStatus = null;
        if (!empty($newStatusOrStatusId)) {
            $canonicalStatus = \App\Services\OrderStatusResolver::resolve($newStatusOrStatusId);
        }
        if (!$canonicalStatus && !empty($parentOrder->status_id)) {
            $canonicalStatus = \App\Services\OrderStatusResolver::resolve($parentOrder->status_id);
        }

        $statusName = $canonicalStatus ? $canonicalStatus->name : (string)($parentOrder->status ?? 'Pending');
        $statusId = $canonicalStatus ? $canonicalStatus->id : $parentOrder->status_id;

        foreach ($comboMemberIds as $childId) {
            $childOrder = PcbOrder::find($childId);
            if (!$childOrder) continue;

            $oldStatus = $childOrder->status ?? 'Pending';
            $statusDiffers = (int)($childOrder->status_id ?? 0) !== (int)($statusId ?? 0);
            $billDiffers = !empty($parentOrder->bill_number) && (string)$childOrder->bill_number !== (string)$parentOrder->bill_number;

            if (!$statusDiffers && !$billDiffers) {
                continue;
            }

            if ($statusId) {
                $childOrder->status_id = $statusId;
            }

            if (!empty($parentOrder->bill_number)) {
                $childOrder->bill_number = $parentOrder->bill_number;
            }

            $childOrder->save();

            // Insert audit log into pcb_order_logs
            if (Schema::hasTable('pcb_order_logs')) {
                DB::table('pcb_order_logs')->insert([
                    'pcb_order_id' => $childOrder->id,
                    'order_number' => $childOrder->order_number ?? (string)$childOrder->id,
                    'admin_id'     => $adminId,
                    'status'       => $statusName,
                    'action'       => "Combo Status Synced: {$statusName}",
                    'description'  => "Status automatically synchronized from main combo order '{$parentOrder->order_number}' from '{$oldStatus}' to '{$statusName}' by {$updaterName}.",
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]);
            }

            // Insert into pcb_order_status_histories
            if (Schema::hasTable('pcb_order_status_histories')) {
                PcbOrderStatusHistory::create([
                    'pcb_order_id' => $childOrder->id,
                    'status_id'    => $statusId,
                    'admin_id'     => $adminId ?: 1,
                    'status_name'  => $statusName,
                    'remark'       => "Status synchronized from parent combo order '{$parentOrder->order_number}' by {$updaterName}",
                ]);
            }
        }
    }
}
