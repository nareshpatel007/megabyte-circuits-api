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
        if (Schema::hasTable('email_templates') && !Schema::hasColumn('email_templates', 'to')) {
            Schema::table('email_templates', function (Blueprint $table) {
                $table->string('to')->nullable()->after('body');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('email_templates') && Schema::hasColumn('email_templates', 'to')) {
            Schema::table('email_templates', function (Blueprint $table) {
                $table->dropColumn('to');
            });
        }
    }
};
