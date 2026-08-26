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
        Schema::table('digikey_products', function (Blueprint $table) {
            $table->dropUnique(['manufacturer_product_number']);
            $table->string('manufacturer_product_number')->nullable()->change();
            $table->unique('digikey_product_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('digikey_products', function (Blueprint $table) {
            $table->dropUnique(['digikey_product_number']);
            $table->string('manufacturer_product_number')->unique()->change();
        });
    }
};
