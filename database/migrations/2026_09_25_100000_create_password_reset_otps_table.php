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
        if (!Schema::hasTable('password_reset_otps')) {
            Schema::create('password_reset_otps', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('admin_id')->nullable()->index();
                $table->string('identifier')->index();
                $table->string('otp_hash');
                $table->timestamp('expires_at')->index();
                $table->timestamp('verified_at')->nullable();
                $table->integer('attempts')->default(0);
                $table->integer('max_attempts')->default(5);
                $table->timestamp('last_sent_at')->nullable();
                $table->timestamp('used_at')->nullable();
                $table->string('reset_token_hash')->nullable()->index();
                $table->timestamp('reset_token_expires_at')->nullable();
                $table->string('ip_address')->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->timestamps();
            });
        }

        // Ensure admins table has mobile / phone column if not present
        if (Schema::hasTable('admins')) {
            Schema::table('admins', function (Blueprint $table) {
                if (!Schema::hasColumn('admins', 'mobile')) {
                    $table->string('mobile')->nullable()->after('email');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('password_reset_otps');
    }
};
