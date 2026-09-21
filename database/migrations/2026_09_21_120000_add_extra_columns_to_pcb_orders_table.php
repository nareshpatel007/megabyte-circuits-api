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
            if (!Schema::hasColumn('pcb_orders', 'q_no')) {
                $table->string('q_no', 100)->nullable()->after('order_number');
            }
            if (!Schema::hasColumn('pcb_orders', 'c_g')) {
                $table->string('c_g', 50)->nullable()->after('q_no');
            }
            if (!Schema::hasColumn('pcb_orders', 'combo')) {
                $table->string('combo', 100)->nullable()->after('c_g');
            }
            if (!Schema::hasColumn('pcb_orders', 'order_qty')) {
                $table->integer('order_qty')->default(0)->after('completed_qty');
            }
            if (!Schema::hasColumn('pcb_orders', 'launch_qty')) {
                $table->integer('launch_qty')->default(0)->after('order_qty');
            }
            if (!Schema::hasColumn('pcb_orders', 'panel_qty')) {
                $table->integer('panel_qty')->default(0)->after('launch_qty');
            }
            if (!Schema::hasColumn('pcb_orders', 'ups_qty')) {
                $table->integer('ups_qty')->default(0)->after('panel_qty');
            }
            if (!Schema::hasColumn('pcb_orders', 'final_qty')) {
                $table->integer('final_qty')->default(0)->after('ups_qty');
            }
            if (!Schema::hasColumn('pcb_orders', 'failed_qty')) {
                $table->integer('failed_qty')->default(0)->after('final_qty');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pcb_orders', function (Blueprint $table) {
            $columnsToDrop = array_filter([
                'q_no', 'c_g', 'combo', 'order_qty', 'launch_qty', 
                'panel_qty', 'ups_qty', 'final_qty', 'failed_qty'
            ], fn($col) => Schema::hasColumn('pcb_orders', $col));

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
