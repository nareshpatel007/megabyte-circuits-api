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
        if (Schema::hasTable('email_logs') && !Schema::hasColumn('email_logs', 'is_test')) {
            Schema::table('email_logs', function (Blueprint $table) {
                $table->boolean('is_test')->default(false)->after('status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('email_logs') && Schema::hasColumn('email_logs', 'is_test')) {
            Schema::table('email_logs', function (Blueprint $table) {
                $table->dropColumn('is_test');
            });
        }
    }
};
