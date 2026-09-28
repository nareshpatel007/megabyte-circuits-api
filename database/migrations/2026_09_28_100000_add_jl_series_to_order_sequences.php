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
        if (Schema::hasTable('order_sequences')) {
            $existingJL = DB::table('order_sequences')->where('series', 'JL')->first();
            if (!$existingJL) {
                // Initialize JL series counter from existing max numeric JL order number (if any)
                $lastJLOrder = DB::table('pcb_orders')
                    ->where('order_number', 'LIKE', 'JL%')
                    ->where('order_number', 'NOT LIKE', '%-%')
                    ->orderBy('id', 'desc')
                    ->first();

                $initialJL = 0;
                if ($lastJLOrder && !empty($lastJLOrder->order_number)) {
                    $initialJL = (int) preg_replace('/[^0-9]/', '', $lastJLOrder->order_number);
                }

                DB::table('order_sequences')->insert([
                    'series' => 'JL',
                    'last_number' => $initialJL,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('order_sequences')) {
            DB::table('order_sequences')->where('series', 'JL')->delete();
        }
    }
};
