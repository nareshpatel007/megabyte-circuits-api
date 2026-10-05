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
        if (Schema::hasTable('pcb_orders')) {
            Schema::table('pcb_orders', function (Blueprint $table) {
                if (!Schema::hasColumn('pcb_orders', 'delivery_method')) {
                    $table->string('delivery_method', 50)->nullable()->after('delivery_date');
                }
            });

            // Safely backfill only genuine historical data where shipping_option exists in pcb_order_meta
            if (Schema::hasTable('pcb_order_meta')) {
                DB::statement("
                    UPDATE pcb_orders o
                    JOIN pcb_order_meta m ON o.id = m.pcb_order_id
                    SET o.delivery_method = LOWER(TRIM(m.meta_value))
                    WHERE m.meta_key = 'shipping_option'
                      AND m.meta_value IS NOT NULL
                      AND TRIM(m.meta_value) != ''
                      AND o.delivery_method IS NULL
                ");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('pcb_orders')) {
            Schema::table('pcb_orders', function (Blueprint $table) {
                if (Schema::hasColumn('pcb_orders', 'delivery_method')) {
                    $table->dropColumn('delivery_method');
                }
            });
        }
    }
};
