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
            if (!Schema::hasColumn('pcb_orders', 'layers')) {
                $table->string('layers', 50)->nullable()->after('combo');
            }
            if (!Schema::hasColumn('pcb_orders', 'mask')) {
                $table->string('mask', 100)->nullable()->after('layers');
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
                'layers', 'mask'
            ], fn($col) => Schema::hasColumn('pcb_orders', $col));

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
