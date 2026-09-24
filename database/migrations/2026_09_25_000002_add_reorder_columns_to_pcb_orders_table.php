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
        if (Schema::hasTable('pcb_orders')) {
            Schema::table('pcb_orders', function (Blueprint $table) {
                if (!Schema::hasColumn('pcb_orders', 'source_order_id')) {
                    $table->unsignedBigInteger('source_order_id')->nullable()->after('gerber_file_id')->index();
                }
                if (!Schema::hasColumn('pcb_orders', 'source_order_number')) {
                    $table->string('source_order_number')->nullable()->after('source_order_id')->index();
                }
                if (!Schema::hasColumn('pcb_orders', 'is_reorder')) {
                    $table->boolean('is_reorder')->default(false)->after('source_order_number');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('pcb_orders')) {
            Schema::table('pcb_orders', function (Blueprint $table) {
                if (Schema::hasColumn('pcb_orders', 'source_order_id')) {
                    $table->dropColumn('source_order_id');
                }
                if (Schema::hasColumn('pcb_orders', 'source_order_number')) {
                    $table->dropColumn('source_order_number');
                }
                if (Schema::hasColumn('pcb_orders', 'is_reorder')) {
                    $table->dropColumn('is_reorder');
                }
            });
        }
    }
};
