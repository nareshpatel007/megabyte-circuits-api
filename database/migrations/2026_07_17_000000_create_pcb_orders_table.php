<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop existing tables to recreate clean normalized structure
        Schema::dropIfExists('pcb_order_meta');
        Schema::dropIfExists('pcb_orders');

        // Main Compact PCB Orders Table
        Schema::create('pcb_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('order_number', 50)->unique(); // M0001, M0002...
            $table->string('board_name');
            $table->string('customer_name', 200)->nullable();
            $table->string('user_email');
            $table->string('user_mobile', 15);
            $table->string('status', 50)->default('pending');
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('order_value', 10, 2)->default(0);
            $table->date('delivery_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('order_number');
            $table->index('user_email');
            $table->index('user_mobile');
            $table->index('status');
        });

        // Separate Key-Value Meta Specifications Table
        Schema::create('pcb_order_meta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pcb_order_id')->constrained('pcb_orders')->onDelete('cascade');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();

            $table->index(['pcb_order_id', 'meta_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pcb_order_meta');
        Schema::dropIfExists('pcb_orders');
    }
};
