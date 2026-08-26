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
        Schema::create('digikey_categories', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('category_id')->unique();
            $table->bigInteger('parent_id')->default(0)->index();
            $table->string('name');
            $table->bigInteger('product_count')->default(0);
            $table->json('raw_response')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('digikey_categories');
    }
};
