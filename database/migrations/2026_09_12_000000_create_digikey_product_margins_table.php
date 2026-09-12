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
        Schema::create('digikey_product_margins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('digikey_product_id')->nullable()->unique()->index();
            $table->enum('margin_type', ['percentage', 'fixed'])->default('percentage');
            $table->decimal('margin_value', 12, 4)->default(0.0000);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('digikey_product_margins');
    }
};
