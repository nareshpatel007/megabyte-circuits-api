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
            $table->json('product_variations')->nullable()->after('photo_url');
            $table->json('parameters')->nullable()->after('product_variations');
            $table->json('classifications')->nullable()->after('parameters');
            $table->json('series')->nullable()->after('classifications');
            $table->json('other_names')->nullable()->after('series');
            $table->boolean('back_order_not_allowed')->default(false)->after('other_names');
            $table->boolean('normally_stocking')->default(true)->after('back_order_not_allowed');
            $table->boolean('discontinued')->default(false)->after('normally_stocking');
            $table->boolean('end_of_life')->default(false)->after('discontinued');
            $table->boolean('ncnr')->default(false)->after('end_of_life');
            $table->string('primary_video_url', 1000)->nullable()->after('ncnr');
            $table->string('manufacturer_lead_weeks')->nullable()->after('primary_video_url');
            $table->bigInteger('manufacturer_public_quantity')->default(0)->after('manufacturer_lead_weeks');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('digikey_products', function (Blueprint $table) {
            $table->dropColumn([
                'product_variations',
                'parameters',
                'classifications',
                'series',
                'other_names',
                'back_order_not_allowed',
                'normally_stocking',
                'discontinued',
                'end_of_life',
                'ncnr',
                'primary_video_url',
                'manufacturer_lead_weeks',
                'manufacturer_public_quantity',
            ]);
        });
    }
};
