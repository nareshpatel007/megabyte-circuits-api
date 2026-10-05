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
        if (!Schema::hasTable('pcb_orders') || !Schema::hasTable('pcb_order_combos')) {
            return;
        }

        $comboOrders = DB::table('pcb_orders')
            ->whereNotNull('combo')
            ->where('combo', '!=', '')
            ->get();

        foreach ($comboOrders as $parent) {
            $comboStr = trim((string)$parent->combo);
            $parts = preg_split('/[\+,\s]+/', $comboStr);

            foreach ($parts as $p) {
                $childNo = strtoupper(trim($p));
                if ($childNo === '' || strcasecmp($childNo, $parent->order_number) === 0) {
                    continue;
                }

                $child = DB::table('pcb_orders')
                    ->whereRaw('UPPER(TRIM(order_number)) = ?', [$childNo])
                    ->first();

                if ($child && $child->id !== $parent->id) {
                    $exists = DB::table('pcb_order_combos')
                        ->where('parent_order_id', $parent->id)
                        ->where('combo_order_id', $child->id)
                        ->exists();

                    if (!$exists) {
                        DB::table('pcb_order_combos')->insert([
                            'parent_order_id' => $parent->id,
                            'combo_order_id'  => $child->id,
                            'created_at'      => now(),
                            'updated_at'      => now(),
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reversible if needed
    }
};
