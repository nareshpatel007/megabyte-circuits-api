<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pcb_orders', function (Blueprint $table) {
            $table->index('status', 'idx_pcb_orders_status');
            $table->index('delivery_date', 'idx_pcb_orders_delivery_date');
            $table->index('created_at', 'idx_pcb_orders_created_at');
            $table->index(['status', 'delivery_date'], 'idx_pcb_orders_status_delivery');
        });

        if (Schema::hasTable('pcb_order_metas')) {
            Schema::table('pcb_order_metas', function (Blueprint $table) {
                $table->index(['pcb_order_id', 'meta_key'], 'idx_pcb_order_metas_order_key');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pcb_orders', function (Blueprint $table) {
            $table->dropIndex('idx_pcb_orders_status');
            $table->dropIndex('idx_pcb_orders_delivery_date');
            $table->dropIndex('idx_pcb_orders_created_at');
            $table->dropIndex('idx_pcb_orders_status_delivery');
        });

        if (Schema::hasTable('pcb_order_metas')) {
            Schema::table('pcb_order_metas', function (Blueprint $table) {
                $table->dropIndex('idx_pcb_order_metas_order_key');
            });
        }
    }
};
