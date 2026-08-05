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
        if (Schema::hasTable('pcb_order_statuses')) {
            Schema::table('pcb_order_statuses', function (Blueprint $table) {
                if (!Schema::hasColumn('pcb_order_statuses', 'slug')) {
                    $table->string('slug')->nullable()->after('name');
                }
                if (!Schema::hasColumn('pcb_order_statuses', 'color')) {
                    $table->string('color')->default('#10b981')->after('sort_order');
                }
                if (!Schema::hasColumn('pcb_order_statuses', 'is_active')) {
                    $table->boolean('is_active')->default(true)->after('color');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('pcb_order_statuses')) {
            Schema::table('pcb_order_statuses', function (Blueprint $table) {
                if (Schema::hasColumn('pcb_order_statuses', 'slug')) {
                    $table->dropColumn('slug');
                }
                if (Schema::hasColumn('pcb_order_statuses', 'color')) {
                    $table->dropColumn('color');
                }
                if (Schema::hasColumn('pcb_order_statuses', 'is_active')) {
                    $table->dropColumn('is_active');
                }
            });
        }
    }
};
