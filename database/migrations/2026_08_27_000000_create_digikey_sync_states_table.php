<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digikey_sync_states', function (Blueprint $table) {
            $table->id();
            $table->integer('last_cat_index')->default(0);
            $table->integer('last_mfg_index')->default(0);
            $table->bigInteger('total_synced_products')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digikey_sync_states');
    }
};
