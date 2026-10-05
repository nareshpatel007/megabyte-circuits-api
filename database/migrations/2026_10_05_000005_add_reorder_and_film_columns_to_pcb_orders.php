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
                if (!Schema::hasColumn('pcb_orders', 'film_applied')) {
                    $table->boolean('film_applied')->default(false)->after('status');
                }
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

            // Backfill film_applied from pcb_order_meta if available
            if (Schema::hasTable('pcb_order_meta')) {
                $filmMetas = DB::table('pcb_order_meta')
                    ->where('meta_key', 'film_applied')
                    ->pluck('meta_value', 'pcb_order_id');

                foreach ($filmMetas as $orderId => $val) {
                    $boolVal = filter_var($val, FILTER_VALIDATE_BOOLEAN) || $val === '1' || $val === 1;
                    if ($boolVal) {
                        DB::table('pcb_orders')->where('id', $orderId)->update(['film_applied' => 1]);
                    }
                }

                // Backfill is_reorder from pcb_order_meta if available
                $reorderMetas = DB::table('pcb_order_meta')
                    ->where('meta_key', 'is_reorder')
                    ->pluck('meta_value', 'pcb_order_id');

                foreach ($reorderMetas as $orderId => $val) {
                    $boolVal = filter_var($val, FILTER_VALIDATE_BOOLEAN) || $val === '1' || $val === 1;
                    if ($boolVal) {
                        DB::table('pcb_orders')->where('id', $orderId)->update(['is_reorder' => 1]);
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
        if (Schema::hasTable('pcb_orders')) {
            Schema::table('pcb_orders', function (Blueprint $table) {
                $cols = [];
                if (Schema::hasColumn('pcb_orders', 'film_applied')) $cols[] = 'film_applied';
                if (Schema::hasColumn('pcb_orders', 'source_order_id')) $cols[] = 'source_order_id';
                if (Schema::hasColumn('pcb_orders', 'source_order_number')) $cols[] = 'source_order_number';
                if (Schema::hasColumn('pcb_orders', 'is_reorder')) $cols[] = 'is_reorder';
                if (!empty($cols)) {
                    $table->dropColumn($cols);
                }
            });
        }
    }
};
