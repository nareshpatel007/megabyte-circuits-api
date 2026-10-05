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

            // 1. Backfill layers if currently empty
            if (empty($order->layers) || trim((string)$order->layers) === '') {
                $layerRows = DB::table('pcb_order_meta')
                    ->where('pcb_order_id', $order->id)
                    ->whereIn('meta_key', ['layers', 'layer'])
                    ->pluck('meta_value')
                    ->toArray();

                $cleanLayerVals = [];
                foreach ($layerRows as $lVal) {
                    $c = trim((string)$lVal);
                    if ($c !== '') {
                        $cleanLayerVals[] = $c;
                    }
                }

                $uniqueLayers = array_values(array_unique($cleanLayerVals));
                if (count($uniqueLayers) >= 1) {
                    $updates['layers'] = $uniqueLayers[0];
                }
            }

            // 2. Backfill mask if currently empty
            if (empty($order->mask) || trim((string)$order->mask) === '') {
                $maskRows = DB::table('pcb_order_meta')
                    ->where('pcb_order_id', $order->id)
                    ->whereIn('meta_key', ['pcb_color', 'mask', 'solder_mask'])
                    ->pluck('meta_value')
                    ->toArray();

                $cleanMaskVals = [];
                foreach ($maskRows as $mVal) {
                    $c = trim((string)$mVal);
                    if ($c !== '') {
                        $cleanMaskVals[] = $c;
                    }
                }

                $uniqueMasks = array_values(array_unique($cleanMaskVals));
                if (count($uniqueMasks) >= 1) {
                    $updates['mask'] = $uniqueMasks[0];
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
