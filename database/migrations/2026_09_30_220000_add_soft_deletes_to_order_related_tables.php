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
        $tables = [
            'pcb_order_meta',
            'payment_transactions',
            'pcb_order_status_histories',
            'pcb_order_notes',
            'job_card_documents',
            'pcb_order_combos',
            'pcb_order_old_orders'
        ];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName) && !Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->softDeletes();
                });
            }
        }

        // Back-fill soft-delete timestamp for any existing soft-deleted orders
        try {
            if (Schema::hasTable('pcb_orders') && Schema::hasColumn('pcb_orders', 'deleted_at')) {
                $deletedOrders = DB::table('pcb_orders')
                    ->whereNotNull('deleted_at')
                    ->select('id', 'transaction_id', 'deleted_at')
                    ->get();

                foreach ($deletedOrders as $order) {
                    $deletedAt = $order->deleted_at ?: now()->toDateTimeString();

                    // 1. Soft-delete meta data
                    if (Schema::hasTable('pcb_order_meta') && Schema::hasColumn('pcb_order_meta', 'deleted_at')) {
                        DB::table('pcb_order_meta')
                            ->where('pcb_order_id', $order->id)
                            ->whereNull('deleted_at')
                            ->update(['deleted_at' => $deletedAt]);
                    }

                    // 2. Soft-delete status histories
                    if (Schema::hasTable('pcb_order_status_histories') && Schema::hasColumn('pcb_order_status_histories', 'deleted_at')) {
                        DB::table('pcb_order_status_histories')
                            ->where('pcb_order_id', $order->id)
                            ->whereNull('deleted_at')
                            ->update(['deleted_at' => $deletedAt]);
                    }

                    // 3. Soft-delete notes
                    if (Schema::hasTable('pcb_order_notes') && Schema::hasColumn('pcb_order_notes', 'deleted_at')) {
                        DB::table('pcb_order_notes')
                            ->where('pcb_order_id', $order->id)
                            ->whereNull('deleted_at')
                            ->update(['deleted_at' => $deletedAt]);
                    }

                    // 4. Soft-delete job card documents
                    if (Schema::hasTable('job_card_documents') && Schema::hasColumn('job_card_documents', 'deleted_at')) {
                        DB::table('job_card_documents')
                            ->where('pcb_order_id', $order->id)
                            ->whereNull('deleted_at')
                            ->update(['deleted_at' => $deletedAt]);
                    }

                    // 5. Soft-delete combos
                    if (Schema::hasTable('pcb_order_combos') && Schema::hasColumn('pcb_order_combos', 'deleted_at')) {
                        DB::table('pcb_order_combos')
                            ->where(function ($q) use ($order) {
                                $q->where('parent_order_id', $order->id)
                                  ->orWhere('combo_order_id', $order->id);
                            })
                            ->whereNull('deleted_at')
                            ->update(['deleted_at' => $deletedAt]);
                    }

                    // 6. Soft-delete old order references
                    if (Schema::hasTable('pcb_order_old_orders') && Schema::hasColumn('pcb_order_old_orders', 'deleted_at')) {
                        DB::table('pcb_order_old_orders')
                            ->where(function ($q) use ($order) {
                                $q->where('order_id', $order->id)
                                  ->orWhere('old_order_id', $order->id);
                            })
                            ->whereNull('deleted_at')
                            ->update(['deleted_at' => $deletedAt]);
                    }

                    // 7. Soft-delete payment transaction if no active order is linked to it
                    if (!empty($order->transaction_id) && Schema::hasTable('payment_transactions') && Schema::hasColumn('payment_transactions', 'deleted_at')) {
                        $hasOtherActiveOrder = DB::table('pcb_orders')
                            ->where('transaction_id', $order->transaction_id)
                            ->where('id', '!=', $order->id)
                            ->whereNull('deleted_at')
                            ->exists();

                        if (!$hasOtherActiveOrder) {
                            DB::table('payment_transactions')
                                ->where('id', $order->transaction_id)
                                ->whereNull('deleted_at')
                                ->update(['deleted_at' => $deletedAt]);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Log but don't fail migration
            \Illuminate\Support\Facades\Log::warning("Back-filling soft-delete for order relations had notice: " . $e->getMessage());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'pcb_order_meta',
            'payment_transactions',
            'pcb_order_status_histories',
            'pcb_order_notes',
            'job_card_documents',
            'pcb_order_combos',
            'pcb_order_old_orders'
        ];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropSoftDeletes();
                });
            }
        }
    }
};
