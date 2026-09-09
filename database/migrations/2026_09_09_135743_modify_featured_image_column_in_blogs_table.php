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
        Schema::table('blogs', function (Blueprint $table) {
            $table->longText('featured_image')->nullable()->change();
            $table->longText('og_image')->nullable()->change();
            $table->longText('twitter_image')->nullable()->change();
            $table->longText('schema_markup')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('blogs', function (Blueprint $table) {
            $table->string('featured_image', 500)->nullable()->change();
            $table->string('og_image', 500)->nullable()->change();
            $table->string('twitter_image', 500)->nullable()->change();
            $table->text('schema_markup')->nullable()->change();
        });
    }
};
