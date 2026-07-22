<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcb_users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('mobile', 20)->nullable();
            $table->string('password_hash')->nullable();
            $table->string('company_name', 200)->nullable();
            $table->string('gst_number', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->index('email');
            $table->index('mobile');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pcb_users');
    }
};
