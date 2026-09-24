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
        // 1. Create order_sequences table for atomic sequence counters (M, J series)
        if (!Schema::hasTable('order_sequences')) {
            Schema::create('order_sequences', function (Blueprint $table) {
                $table->id();
                $table->string('series', 10)->unique();
                $table->unsignedBigInteger('last_number')->default(0);
                $table->timestamps();
            });

            // Initialize M series counter from existing max numeric M order number
            $lastMOrder = DB::table('pcb_orders')
                ->where('order_number', 'LIKE', 'M%')
                ->where('order_number', 'NOT LIKE', '%-%')
                ->orderBy('id', 'desc')
                ->first();

            $initialM = 0;
            if ($lastMOrder && !empty($lastMOrder->order_number)) {
                $num = (int) preg_replace('/[^0-9]/', '', $lastMOrder->order_number);
                $maxId = DB::table('pcb_orders')->max('id') ?? 0;
                $initialM = max($num, $maxId);
            } else {
                $initialM = DB::table('pcb_orders')->max('id') ?? 0;
            }

            // Initialize J series counter from existing max numeric J order number (if any)
            $lastJOrder = DB::table('pcb_orders')
                ->where('order_number', 'LIKE', 'J%')
                ->where('order_number', 'NOT LIKE', '%-%')
                ->orderBy('id', 'desc')
                ->first();

            $initialJ = 0;
            if ($lastJOrder && !empty($lastJOrder->order_number)) {
                $initialJ = (int) preg_replace('/[^0-9]/', '', $lastJOrder->order_number);
            }

            DB::table('order_sequences')->insert([
                [
                    'series' => 'M',
                    'last_number' => $initialM,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ],
                [
                    'series' => 'J',
                    'last_number' => $initialJ,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ],
            ]);
        }

        // 2. Add order_type, quotation_source, jlcpcb_file_key, jlcpcb_quotation_snapshot to pcb_orders
        Schema::table('pcb_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('pcb_orders', 'order_type')) {
                $table->string('order_type', 50)->default('normal')->after('order_number');
                $table->index('order_type');
            }
            if (!Schema::hasColumn('pcb_orders', 'quotation_source')) {
                $table->string('quotation_source', 50)->default('internal')->after('order_type');
                $table->index('quotation_source');
            }
            if (!Schema::hasColumn('pcb_orders', 'jlcpcb_file_key')) {
                $table->string('jlcpcb_file_key', 255)->nullable()->after('gerber_file_id');
            }
            if (!Schema::hasColumn('pcb_orders', 'jlcpcb_quotation_snapshot')) {
                $table->json('jlcpcb_quotation_snapshot')->nullable()->after('jlcpcb_file_key');
            }
        });

        // 3. Backfill order_type and quotation_source for existing records if needed
        DB::table('pcb_orders')
            ->whereNull('order_type')
            ->update([
                'order_type' => 'normal',
                'quotation_source' => 'internal'
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pcb_orders', function (Blueprint $table) {
            $colsToDrop = array_filter([
                'order_type', 'quotation_source', 'jlcpcb_file_key', 'jlcpcb_quotation_snapshot'
            ], fn($col) => Schema::hasColumn('pcb_orders', $col));

            if (!empty($colsToDrop)) {
                $table->dropColumn($colsToDrop);
            }
        });

        Schema::dropIfExists('order_sequences');
    }
};
