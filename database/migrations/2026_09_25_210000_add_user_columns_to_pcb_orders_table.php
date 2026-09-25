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
        if (Schema::hasTable('pcb_orders')) {
            Schema::table('pcb_orders', function (Blueprint $table) {
                if (!Schema::hasColumn('pcb_orders', 'customer_name')) {
                    $table->string('customer_name', 200)->nullable();
                }
                if (!Schema::hasColumn('pcb_orders', 'user_email')) {
                    $table->string('user_email', 200)->nullable();
                }
                if (!Schema::hasColumn('pcb_orders', 'user_mobile')) {
                    $table->string('user_mobile', 50)->nullable();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('pcb_orders')) {
            Schema::table('pcb_orders', function (Blueprint $table) {
                $dropCols = [];
                if (Schema::hasColumn('pcb_orders', 'customer_name')) {
                    $dropCols[] = 'customer_name';
                }
                if (Schema::hasColumn('pcb_orders', 'user_email')) {
                    $dropCols[] = 'user_email';
                }
                if (Schema::hasColumn('pcb_orders', 'user_mobile')) {
                    $dropCols[] = 'user_mobile';
                }
                if (!empty($dropCols)) {
                    $table->dropColumn($dropCols);
                }
            });
        }
    }
};
