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
            $table->json('base_product_number')->nullable()->after('other_names');
            $table->json('category_details')->nullable()->after('base_product_number');
            $table->string('date_last_buy_chance')->nullable()->after('category_details');
            $table->json('shipping_info')->nullable()->after('date_last_buy_chance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('digikey_products', function (Blueprint $table) {
            $table->dropColumn([
                'base_product_number',
                'category_details',
                'date_last_buy_chance',
                'shipping_info',
            ]);
        });
    }
};
