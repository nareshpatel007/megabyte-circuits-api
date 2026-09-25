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
        if (!Schema::hasTable('pending_registrations')) {
            Schema::create('pending_registrations', function (Blueprint $table) {
                $table->id();
                $table->string('registration_token_hash')->unique()->index();
                $table->string('email')->index();
                $table->string('username')->nullable();
                $table->string('name');
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('password_hash');
                $table->string('company_name')->nullable();
                $table->string('country')->nullable();
                $table->string('gst_number')->nullable();
                $table->string('phone')->nullable();
                $table->string('referral_source')->nullable();
                $table->string('invite_token')->nullable();
                $table->json('payload')->nullable();
                $table->string('otp_hash');
                $table->timestamp('otp_expires_at')->index();
                $table->integer('otp_attempts')->default(0);
                $table->integer('max_attempts')->default(5);
                $table->timestamp('last_otp_sent_at')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamp('used_at')->nullable();
                $table->string('ip_address')->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pending_registrations');
    }
};
