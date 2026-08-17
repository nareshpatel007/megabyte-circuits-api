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
        Schema::create('digikey_products', function (Blueprint $table) {
            $table->id();
            $table->string('digikey_product_number')->nullable()->index();
            $table->string('manufacturer_product_number')->unique();
            $table->string('manufacturer_name')->nullable();
            $table->integer('manufacturer_id')->nullable();
            $table->text('product_description')->nullable();
            $table->text('detailed_description')->nullable();
            $table->decimal('unit_price', 12, 4)->default(0);
            $table->string('product_url', 1000)->nullable();
            $table->string('datasheet_url', 1000)->nullable();
            $table->string('photo_url', 1000)->nullable();
            $table->bigInteger('quantity_available')->default(0);
            $table->string('product_status')->nullable();
            $table->string('search_keyword')->nullable()->index();
            $table->json('raw_response')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('digikey_products');
    }
};
