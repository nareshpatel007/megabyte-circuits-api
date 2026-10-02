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
        // 1. Clean up duplicate and soft-deleted rows in pcb_order_combos
        if (Schema::hasTable('pcb_order_combos')) {
            try {
                // Delete soft-deleted pivot records that may be lingering
                if (Schema::hasColumn('pcb_order_combos', 'deleted_at')) {
                    DB::table('pcb_order_combos')->whereNotNull('deleted_at')->delete();
                }

                // Deduplicate active rows: keep the row with the max(id) for each parent_order_id and combo_order_id
                $duplicates = DB::table('pcb_order_combos')
                    ->select('parent_order_id', 'combo_order_id', DB::raw('MAX(id) as keep_id'), DB::raw('COUNT(*) as count'))
                    ->groupBy('parent_order_id', 'combo_order_id')
                    ->having('count', '>', 1)
                    ->get();

                foreach ($duplicates as $dup) {
                    DB::table('pcb_order_combos')
                        ->where('parent_order_id', $dup->parent_order_id)
                        ->where('combo_order_id', $dup->combo_order_id)
                        ->where('id', '<', $dup->keep_id)
                        ->delete();
                }
            } catch (\Throwable $e) {
                // Continue if cleanup error
            }
        }

        // 2. Clean up duplicate and soft-deleted rows in pcb_order_old_orders
        if (Schema::hasTable('pcb_order_old_orders')) {
            try {
                if (Schema::hasColumn('pcb_order_old_orders', 'deleted_at')) {
                    DB::table('pcb_order_old_orders')->whereNotNull('deleted_at')->delete();
                }

                $duplicatesOld = DB::table('pcb_order_old_orders')
                    ->select('order_id', 'old_order_id', DB::raw('MAX(id) as keep_id'), DB::raw('COUNT(*) as count'))
                    ->groupBy('order_id', 'old_order_id')
                    ->having('count', '>', 1)
                    ->get();

                foreach ($duplicatesOld as $dup) {
                    DB::table('pcb_order_old_orders')
                        ->where('order_id', $dup->order_id)
                        ->where('old_order_id', $dup->old_order_id)
                        ->where('id', '<', $dup->keep_id)
                        ->delete();
                }
            } catch (\Throwable $e) {
                // Continue if cleanup error
            }
        }

        // 3. Deduplicate string values in pcb_orders.combo
        if (Schema::hasTable('pcb_orders') && Schema::hasColumn('pcb_orders', 'combo')) {
            try {
                $ordersWithCombo = DB::table('pcb_orders')
                    ->whereNotNull('combo')
                    ->where('combo', '!=', '')
                    ->select('id', 'combo')
                    ->get();

                foreach ($ordersWithCombo as $ord) {
                    $parts = preg_split('/[\+,\s]+/', trim((string)$ord->combo));
                    $cleaned = [];
                    foreach ($parts as $p) {
                        $v = strtoupper(trim($p));
                        if ($v !== '') {
                            $cleaned[] = $v;
                        }
                    }
                    $unique = array_values(array_unique($cleaned));
                    $newCombo = !empty($unique) ? implode(', ', $unique) : null;

                    if ($newCombo !== $ord->combo) {
                        DB::table('pcb_orders')->where('id', $ord->id)->update(['combo' => $newCombo]);
                    }
                }
            } catch (\Throwable $e) {
                // Continue
            }
        }

        // 4. Deduplicate string values in pcb_orders.old_order_number
        if (Schema::hasTable('pcb_orders') && Schema::hasColumn('pcb_orders', 'old_order_number')) {
            try {
                $ordersWithOld = DB::table('pcb_orders')
                    ->whereNotNull('old_order_number')
                    ->where('old_order_number', '!=', '')
                    ->select('id', 'old_order_number')
                    ->get();

                foreach ($ordersWithOld as $ord) {
                    $parts = preg_split('/[\+,\s]+/', trim((string)$ord->old_order_number));
                    $cleaned = [];
                    foreach ($parts as $p) {
                        $v = strtoupper(trim($p));
                        if ($v !== '') {
                            $cleaned[] = $v;
                        }
                    }
                    $unique = array_values(array_unique($cleaned));
                    $newOld = !empty($unique) ? implode(', ', $unique) : null;

                    if ($newOld !== $ord->old_order_number) {
                        DB::table('pcb_orders')->where('id', $ord->id)->update(['old_order_number' => $newOld]);
                    }
                }
            } catch (\Throwable $e) {
                // Continue
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op
    }
};
