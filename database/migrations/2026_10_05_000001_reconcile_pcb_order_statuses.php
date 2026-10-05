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
        if (!Schema::hasTable('pcb_orders') || !Schema::hasTable('pcb_order_statuses')) {
            return;
        }

        // Dynamically resolve canonical status IDs from pcb_order_statuses
        $pendingStatus = DB::table('pcb_order_statuses')
            ->whereRaw('LOWER(TRIM(name)) = ?', ['pending'])
            ->first();
        $pendingId = $pendingStatus ? $pendingStatus->id : null;

        $underProcessStatus = DB::table('pcb_order_statuses')
            ->whereRaw('LOWER(TRIM(name)) IN (?, ?, ?)', ['under process', 'in production', 'traveler'])
            ->orderBy('id', 'asc')
            ->first();
        $underProcessId = $underProcessStatus ? $underProcessStatus->id : $pendingId;

        if (!$pendingId) {
            throw new \RuntimeException("Canonical 'Pending' status not found in pcb_order_statuses table.");
        }

        // 1. Fix orphan status_id = 1 (Orders M00007, M00008)
        DB::table('pcb_orders')
            ->where('status_id', 1)
            ->update([
                'status_id' => $pendingId,
                'status'    => 'Pending',
                'updated_at' => now(),
            ]);

        // 2. Fix NULL status_id where status is 'Pending' or empty
        DB::table('pcb_orders')
            ->whereNull('status_id')
            ->where(function ($q) {
                $q->where('status', 'Pending')
                  ->orWhereNull('status')
                  ->orWhere('status', '');
            })
            ->update([
                'status_id' => $pendingId,
                'status'    => 'Pending',
                'updated_at' => now(),
            ]);

        // 3. Fix legacy 'processing' text (Order M4684-2)
        DB::table('pcb_orders')
            ->whereNull('status_id')
            ->whereRaw('LOWER(TRIM(status)) = ?', ['processing'])
            ->update([
                'status_id' => $underProcessId,
                'status'    => $underProcessStatus ? $underProcessStatus->name : 'Under Process',
                'updated_at' => now(),
            ]);

        // 4. Synchronize status text column to match pcb_order_statuses.name exactly
        $statuses = DB::table('pcb_order_statuses')->get()->keyBy('id');
        $ordersToSync = DB::table('pcb_orders')->whereNotNull('status_id')->get(['id', 'status', 'status_id']);

        foreach ($ordersToSync as $ord) {
            if (isset($statuses[$ord->status_id])) {
                $canonicalName = $statuses[$ord->status_id]->name;
                if ($ord->status !== $canonicalName) {
                    DB::table('pcb_orders')->where('id', $ord->id)->update([
                        'status' => $canonicalName,
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Status reconciliation is data correction; no destructive rollback needed,
        // but if backup table exists, data could be restored from pcb_orders_backup_20261005.
    }
};
