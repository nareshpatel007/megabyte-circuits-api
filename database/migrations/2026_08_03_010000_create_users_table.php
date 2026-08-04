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
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->nullable();
                $table->string('name')->nullable();
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('email')->unique();
                $table->string('password_hash')->nullable();
                $table->string('password')->nullable();
                $table->string('company_name')->nullable();
                $table->string('phone_number')->nullable();
                $table->string('token')->nullable();
                $table->string('referral_code')->nullable();
                $table->string('referral_source')->nullable();
                $table->integer('available_credits')->default(50);
                $table->integer('total_bonus_credits')->default(50);
                $table->string('status')->default('active');
                $table->string('country')->nullable();
                $table->string('avatar')->nullable();
                $table->string('api_key')->nullable();
                $table->timestamp('last_login_at')->nullable();
                $table->string('last_login_ip')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
