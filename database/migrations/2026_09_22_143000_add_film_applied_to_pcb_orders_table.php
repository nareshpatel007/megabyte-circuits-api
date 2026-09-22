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
            if (!Schema::hasColumn('pcb_orders', 'film_applied')) {
                $table->boolean('film_applied')->default(false);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pcb_orders', function (Blueprint $table) {
            if (Schema::hasColumn('pcb_orders', 'film_applied')) {
                $table->dropColumn('film_applied');
            }
        });
    }
};
