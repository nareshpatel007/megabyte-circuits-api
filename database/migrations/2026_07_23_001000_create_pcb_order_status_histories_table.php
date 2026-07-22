<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcb_order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pcb_order_id')->constrained('pcb_orders')->onDelete('cascade');
            $table->string('admin_name')->default('Admin');
            $table->string('status_name');
            $table->string('remark')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('pcb_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pcb_order_status_histories');
    }
};
