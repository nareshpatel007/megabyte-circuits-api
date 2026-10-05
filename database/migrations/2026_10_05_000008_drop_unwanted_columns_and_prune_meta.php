<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Drops redundant columns from pcb_orders (status, completed_qty)
     * and prunes duplicate records from pcb_order_meta.
     */
    public function up(): void
    {
        // 1. Drop redundant physical columns from pcb_orders
        Schema::table('pcb_orders', function (Blueprint $table) {
            $colsToDrop = [];
            if (Schema::hasColumn('pcb_orders', 'status')) {
                $colsToDrop[] = 'status';
            }
            if (Schema::hasColumn('pcb_orders', 'completed_qty')) {
                $colsToDrop[] = 'completed_qty';
            }
            if (!empty($colsToDrop)) {
                $table->dropColumn($colsToDrop);
            }
        });

        // 2. Prune duplicate redundant metadata keys from pcb_order_meta
        if (Schema::hasTable('pcb_order_meta')) {
            DB::table('pcb_order_meta')
                ->whereIn('meta_key', [
                    'order_number',
                    'status',
                    'status_id',
                    'unit_price',
                    'order_value',
                    'gerber_file_id'
                ])
                ->delete();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. Restore columns to pcb_orders
        Schema::table('pcb_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('pcb_orders', 'completed_qty')) {
                $table->integer('completed_qty')->default(0)->after('unit_price');
            }
            if (!Schema::hasColumn('pcb_orders', 'status')) {
                $table->string('status', 50)->nullable()->after('deleted_at');
            }
        });

        // 2. Re-populate completed_qty from final_qty
        if (Schema::hasColumn('pcb_orders', 'completed_qty')) {
            DB::statement("UPDATE pcb_orders SET completed_qty = COALESCE(final_qty, 0)");
        }

        // 3. Re-populate status from pcb_order_statuses
        if (Schema::hasColumn('pcb_orders', 'status') && Schema::hasTable('pcb_order_statuses')) {
            DB::statement("
                UPDATE pcb_orders o
                LEFT JOIN pcb_order_statuses s ON s.id = o.status_id
                SET o.status = COALESCE(s.name, 'Pending')
            ");
        }

        // 4. Restore pruned meta rows from backup table if available
        if (Schema::hasTable('pcb_order_meta_backup_20261005') && Schema::hasTable('pcb_order_meta')) {
            DB::statement("
                INSERT IGNORE INTO pcb_order_meta (pcb_order_id, meta_key, meta_value, created_at, updated_at, deleted_at)
                SELECT pcb_order_id, meta_key, meta_value, created_at, updated_at, deleted_at
                FROM pcb_order_meta_backup_20261005
                WHERE meta_key IN ('order_number', 'status', 'status_id', 'unit_price', 'order_value', 'gerber_file_id')
            ");
        }
    }
};
