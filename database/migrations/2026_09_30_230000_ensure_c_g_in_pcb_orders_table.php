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
                if (!Schema::hasColumn('pcb_orders', 'c_g')) {
                    $table->string('c_g', 20)->nullable()->after('q_no');
                }
            });

            // Normalize existing values to uppercase CASH / GST / BOTH
            DB::table('pcb_orders')
                ->whereRaw("UPPER(TRIM(c_g)) = 'CASH'")
                ->update(['c_g' => 'CASH']);

            DB::table('pcb_orders')
                ->whereRaw("UPPER(TRIM(c_g)) = 'GST'")
                ->update(['c_g' => 'GST']);

            DB::table('pcb_orders')
                ->whereRaw("UPPER(TRIM(c_g)) = 'BOTH'")
                ->update(['c_g' => 'BOTH']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Keep non-destructive rollback
    }
};
