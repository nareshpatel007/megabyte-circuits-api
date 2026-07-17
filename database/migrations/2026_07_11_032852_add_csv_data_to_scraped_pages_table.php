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
        Schema::table('scraped_pages', function (Blueprint $table) {
            $table->longText('csv_data')->nullable()->after('ai_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scraped_pages', function (Blueprint $table) {
            $table->dropColumn('csv_data');
        });
    }
};
