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
        if (!Schema::hasTable('pcb_orders')) {
            return;
        }

        $dbName = DB::getDatabaseName();

        // 1. Helper to check existing FKs
        $existingFks = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $dbName)
            ->where('TABLE_NAME', 'pcb_orders')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->pluck('CONSTRAINT_NAME')
            ->toArray();

        // 2. Helper to check existing indexes
        $existingIndexes = collect(DB::select("SHOW INDEXES FROM pcb_orders"))->pluck('Key_name')->unique()->toArray();

        Schema::table('pcb_orders', function (Blueprint $table) use ($existingFks, $existingIndexes) {
            // Foreign Keys with safe delete rules
            if (!in_array('fk_pcb_orders_status_id', $existingFks) && Schema::hasTable('pcb_order_statuses')) {
                $table->foreign('status_id', 'fk_pcb_orders_status_id')
                    ->references('id')->on('pcb_order_statuses')
                    ->onUpdate('cascade')->onDelete('restrict');
            }

            if (!in_array('fk_pcb_orders_user_id', $existingFks) && Schema::hasTable('users')) {
                $table->foreign('user_id', 'fk_pcb_orders_user_id')
                    ->references('id')->on('users')
                    ->onUpdate('cascade')->onDelete('set null');
            }

            if (!in_array('fk_pcb_orders_transaction_id', $existingFks) && Schema::hasTable('payment_transactions')) {
                $table->foreign('transaction_id', 'fk_pcb_orders_transaction_id')
                    ->references('id')->on('payment_transactions')
                    ->onUpdate('cascade')->onDelete('set null');
            }

            if (!in_array('fk_pcb_orders_shipping_address_id', $existingFks) && Schema::hasTable('user_addresses')) {
                $table->foreign('shipping_address_id', 'fk_pcb_orders_shipping_address_id')
                    ->references('id')->on('user_addresses')
                    ->onUpdate('cascade')->onDelete('set null');
            }

            if (!in_array('fk_pcb_orders_billing_address_id', $existingFks) && Schema::hasTable('user_addresses')) {
                $table->foreign('billing_address_id', 'fk_pcb_orders_billing_address_id')
                    ->references('id')->on('user_addresses')
                    ->onUpdate('cascade')->onDelete('set null');
            }

            if (!in_array('fk_pcb_orders_gerber_file_id', $existingFks) && Schema::hasTable('gerber_files')) {
                $table->foreign('gerber_file_id', 'fk_pcb_orders_gerber_file_id')
                    ->references('id')->on('gerber_files')
                    ->onUpdate('cascade')->onDelete('set null');
            }

            if (!in_array('fk_pcb_orders_source_order_id', $existingFks) && Schema::hasColumn('pcb_orders', 'source_order_id')) {
                $table->foreign('source_order_id', 'fk_pcb_orders_source_order_id')
                    ->references('id')->on('pcb_orders')
                    ->onUpdate('cascade')->onDelete('set null');
            }

            // Performance Indexes
            if (!in_array('idx_pcb_orders_delivery_date', $existingIndexes) && Schema::hasColumn('pcb_orders', 'delivery_date')) {
                $table->index('delivery_date', 'idx_pcb_orders_delivery_date');
            }

            if (!in_array('idx_pcb_orders_created_at', $existingIndexes) && Schema::hasColumn('pcb_orders', 'created_at')) {
                $table->index('created_at', 'idx_pcb_orders_created_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('pcb_orders')) {
            return;
        }

        Schema::table('pcb_orders', function (Blueprint $table) {
            $fksToDrop = [
                'fk_pcb_orders_status_id',
                'fk_pcb_orders_user_id',
                'fk_pcb_orders_transaction_id',
                'fk_pcb_orders_shipping_address_id',
                'fk_pcb_orders_billing_address_id',
                'fk_pcb_orders_gerber_file_id',
                'fk_pcb_orders_source_order_id',
            ];

            foreach ($fksToDrop as $fk) {
                try {
                    $table->dropForeign($fk);
                } catch (\Throwable $e) {}
            }

            $indexesToDrop = [
                'idx_pcb_orders_delivery_date',
                'idx_pcb_orders_created_at',
            ];

            foreach ($indexesToDrop as $idx) {
                try {
                    $table->dropIndex($idx);
                } catch (\Throwable $e) {}
            }
        });
    }
};
