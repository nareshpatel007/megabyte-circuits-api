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
        if (Schema::hasTable('pcb_orders') && !Schema::hasColumn('pcb_orders', 'old_order_number')) {
            Schema::table('pcb_orders', function (Blueprint $table) {
                $table->string('old_order_number', 100)->nullable()->after('combo');
            });
        }

        if (!Schema::hasTable('pcb_order_old_orders')) {
            Schema::create('pcb_order_old_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('pcb_orders')->onDelete('cascade');
                $table->foreignId('old_order_id')->constrained('pcb_orders')->onDelete('cascade');
                $table->timestamps();

                $table->unique(['order_id', 'old_order_id']);
                $table->index('order_id');
                $table->index('old_order_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pcb_order_old_orders');
        if (Schema::hasTable('pcb_orders') && Schema::hasColumn('pcb_orders', 'old_order_number')) {
            Schema::table('pcb_orders', function (Blueprint $table) {
                $table->dropColumn('old_order_number');
            });
        }
    }
};
