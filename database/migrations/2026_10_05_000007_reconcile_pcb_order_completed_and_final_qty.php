<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Reconciles completed_qty and final_qty so they represent the exact same canonical value.
     */
    public function up(): void
    {
        if (!Schema::hasTable('pcb_orders')) {
            return;
        }

        $orders = DB::table('pcb_orders')->get();

        foreach ($orders as $order) {
            $comp = (int)($order->completed_qty ?? 0);
            $fin = (int)($order->final_qty ?? 0);

            if ($comp !== $fin) {
                $canonical = max($comp, $fin);
                DB::table('pcb_orders')
                    ->where('id', $order->id)
                    ->update([
                        'final_qty' => $canonical,
                        'completed_qty' => $canonical,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reversible via pcb_orders_backup_20261005 if needed
    }
};
