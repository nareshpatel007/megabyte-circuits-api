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
        Schema::table('visitor_logs', function (Blueprint $table) {
            $table->string('country')->nullable()->after('ip_address');
            $table->string('region')->nullable()->after('country');
            $table->string('city')->nullable()->after('region');
            $table->json('ip_info')->nullable()->after('city');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visitor_logs', function (Blueprint $table) {
            $table->dropColumn(['country', 'region', 'city', 'ip_info']);
        });
    }
};
