<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Services\OrderStatusResolver;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 1. Creates explicit backup table pcb_orders_backup_before_status_customer_cleanup
     * 2. Ensures pcb_orders.status and pcb_orders.customer_name do not exist
     * 3. Adds canonical status_id and foreign key to pcb_order_status_histories and backfills it
     */
    public function up(): void
    {
        // 1. Create exact backup table as requested in Section 30
        if (!Schema::hasTable('pcb_orders_backup_before_status_customer_cleanup') && Schema::hasTable('pcb_orders')) {
            DB::statement("
                CREATE TABLE pcb_orders_backup_before_status_customer_cleanup
                AS SELECT * FROM pcb_orders
            ");
        }

        // 2. Ensure pcb_orders.status and customer_name are dropped
        Schema::table('pcb_orders', function (Blueprint $table) {
            $colsToDrop = [];
            if (Schema::hasColumn('pcb_orders', 'status')) {
                $colsToDrop[] = 'status';
            }
            if (Schema::hasColumn('pcb_orders', 'customer_name')) {
                $colsToDrop[] = 'customer_name';
            }
            if (!empty($colsToDrop)) {
                $table->dropColumn($colsToDrop);
            }
        });

        // 3. Add canonical status_id to pcb_order_status_histories if not exists
        if (Schema::hasTable('pcb_order_status_histories')) {
            if (!Schema::hasColumn('pcb_order_status_histories', 'status_id')) {
                Schema::table('pcb_order_status_histories', function (Blueprint $table) {
                    $table->unsignedBigInteger('status_id')->nullable()->after('pcb_order_id')->index();
                });
            }

            // Backfill status_id from status_name using canonical resolver or DB join
            if (Schema::hasTable('pcb_order_statuses')) {
                $histories = DB::table('pcb_order_status_histories')
                    ->whereNull('status_id')
                    ->get(['id', 'status_name']);

                foreach ($histories as $h) {
                    $canonical = OrderStatusResolver::resolve($h->status_name);
                    if ($canonical) {
                        DB::table('pcb_order_status_histories')
                            ->where('id', $h->id)
                            ->update(['status_id' => $canonical->id]);
                    }
                }

                // Add foreign key constraint if not exists
                $fkExists = DB::table('information_schema.TABLE_CONSTRAINTS')
                    ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                    ->where('TABLE_NAME', 'pcb_order_status_histories')
                    ->where('CONSTRAINT_NAME', 'fk_pcb_order_status_histories_status_id')
                    ->exists();

                if (!$fkExists) {
                    Schema::table('pcb_order_status_histories', function (Blueprint $table) {
                        $table->foreign('status_id', 'fk_pcb_order_status_histories_status_id')
                            ->references('id')
                            ->on('pcb_order_statuses')
                            ->onDelete('set null');
                    });
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('pcb_order_status_histories')) {
            $fkExists = DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', 'pcb_order_status_histories')
                ->where('CONSTRAINT_NAME', 'fk_pcb_order_status_histories_status_id')
                ->exists();

            if ($fkExists) {
                Schema::table('pcb_order_status_histories', function (Blueprint $table) {
                    $table->dropForeign('fk_pcb_order_status_histories_status_id');
                });
            }

            if (Schema::hasColumn('pcb_order_status_histories', 'status_id')) {
                Schema::table('pcb_order_status_histories', function (Blueprint $table) {
                    $table->dropColumn('status_id');
                });
            }
        }
    }
};
