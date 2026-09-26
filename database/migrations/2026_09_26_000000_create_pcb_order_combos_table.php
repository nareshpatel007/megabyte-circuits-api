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
        if (!Schema::hasTable('pcb_order_combos')) {
            Schema::create('pcb_order_combos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('parent_order_id')->constrained('pcb_orders')->onDelete('cascade');
                $table->foreignId('combo_order_id')->constrained('pcb_orders')->onDelete('cascade');
                $table->timestamps();

                $table->unique(['parent_order_id', 'combo_order_id']);
                $table->unique('combo_order_id');
                $table->index('parent_order_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pcb_order_combos');
    }
};
