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
        if (Schema::hasTable('email_logs') && !Schema::hasColumn('email_logs', 'inventory_item_id')) {
            Schema::table('email_logs', function (Blueprint $table) {
                $table->unsignedBigInteger('inventory_item_id')->nullable()->after('order_id')->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('email_logs') && Schema::hasColumn('email_logs', 'inventory_item_id')) {
            Schema::table('email_logs', function (Blueprint $table) {
                $table->dropColumn('inventory_item_id');
            });
        }
    }
};
