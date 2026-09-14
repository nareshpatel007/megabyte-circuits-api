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
        Schema::create('digikey_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_name', 100);
            $table->string('client_id', 150);
            $table->text('client_secret');
            $table->string('mode', 20)->default('live');
            $table->boolean('is_active')->default(true);
            $table->string('status', 30)->default('active'); // active, rate_limited, error, disabled
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('rate_limited_until')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('digikey_accounts');
    }
};
