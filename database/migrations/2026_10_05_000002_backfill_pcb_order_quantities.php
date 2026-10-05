<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('pcb_orders') || !Schema::hasTable('pcb_order_meta')) {
            return;
        }

        $orders = DB::table('pcb_orders')->get();

        foreach ($orders as $order) {
            $updates = [];

            // 1. Backfill order_qty if currently 0
            if (empty($order->order_qty) || (int)$order->order_qty === 0) {
                $metaQtyRows = DB::table('pcb_order_meta')
                    ->where('pcb_order_id', $order->id)
                    ->whereIn('meta_key', ['order_qty', 'qty', 'quantity'])
                    ->pluck('meta_value')
                    ->toArray();

                $validQtyVals = [];
                foreach ($metaQtyRows as $val) {
                    $cleaned = trim((string)$val);
                    if ($cleaned !== '' && is_numeric($cleaned) && (int)$cleaned > 0) {
                        $validQtyVals[] = (int)$cleaned;
                    }
                }

                $uniqueQtys = array_values(array_unique($validQtyVals));
                // Only backfill if there is unambiguous agreement
                if (count($uniqueQtys) === 1) {
                    $updates['order_qty'] = $uniqueQtys[0];
                }
            }

            // 2. Backfill launch_qty if currently 0
            if (empty($order->launch_qty) || (int)$order->launch_qty === 0) {
                $launchVal = DB::table('pcb_order_meta')
                    ->where('pcb_order_id', $order->id)
                    ->whereIn('meta_key', ['launch_qty', 'launch', 'launched_qty'])
                    ->value('meta_value');
                if ($launchVal !== null && is_numeric(trim($launchVal)) && (int)$launchVal > 0) {
                    $updates['launch_qty'] = (int)$launchVal;
                }
            }

            // 3. Backfill panel_qty if currently 0
            if (empty($order->panel_qty) || (int)$order->panel_qty === 0) {
                $panelVal = DB::table('pcb_order_meta')
                    ->where('pcb_order_id', $order->id)
                    ->whereIn('meta_key', ['panel_qty', 'panel'])
                    ->value('meta_value');
                if ($panelVal !== null && is_numeric(trim($panelVal)) && (int)$panelVal > 0) {
                    $updates['panel_qty'] = (int)$panelVal;
                }
            }

            // 4. Backfill ups_qty if currently 0
            if (empty($order->ups_qty) || (int)$order->ups_qty === 0) {
                $upsVal = DB::table('pcb_order_meta')
                    ->where('pcb_order_id', $order->id)
                    ->whereIn('meta_key', ['ups_qty', 'ups'])
                    ->value('meta_value');
                if ($upsVal !== null && is_numeric(trim($upsVal)) && (int)$upsVal > 0) {
                    $updates['ups_qty'] = (int)$upsVal;
                }
            }

            // 5. Backfill final_qty if currently 0
            if (empty($order->final_qty) || (int)$order->final_qty === 0) {
                $finalVal = DB::table('pcb_order_meta')
                    ->where('pcb_order_id', $order->id)
                    ->whereIn('meta_key', ['final_qty', 'completed_qty', 'final'])
                    ->value('meta_value');
                if ($finalVal !== null && is_numeric(trim($finalVal)) && (int)$finalVal > 0) {
                    $updates['final_qty'] = (int)$finalVal;
                }
            }

            // 6. Backfill failed_qty if currently 0
            if (empty($order->failed_qty) || (int)$order->failed_qty === 0) {
                $failedVal = DB::table('pcb_order_meta')
                    ->where('pcb_order_id', $order->id)
                    ->whereIn('meta_key', ['failed_qty', 'fail_qty', 'failed'])
                    ->value('meta_value');
                if ($failedVal !== null && is_numeric(trim($failedVal)) && (int)$failedVal > 0) {
                    $updates['failed_qty'] = (int)$failedVal;
                }
            }

            if (!empty($updates)) {
                $updates['updated_at'] = now();
                DB::table('pcb_orders')->where('id', $order->id)->update($updates);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reversible if needed via pcb_orders_backup_20261005
    }
};
