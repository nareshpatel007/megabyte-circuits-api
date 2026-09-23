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
        if (Schema::hasTable('email_templates')) {
            Schema::table('email_templates', function (Blueprint $table) {
                if (!Schema::hasColumn('email_templates', 'from_email')) {
                    $table->string('from_email')->nullable()->after('to');
                }
                if (!Schema::hasColumn('email_templates', 'from_name')) {
                    $table->string('from_name')->nullable()->after('from_email');
                }
            });
        }

        if (Schema::hasTable('email_logs')) {
            Schema::table('email_logs', function (Blueprint $table) {
                if (!Schema::hasColumn('email_logs', 'from_email')) {
                    $table->string('from_email')->nullable()->after('customer_id');
                }
                if (!Schema::hasColumn('email_logs', 'from_name')) {
                    $table->string('from_name')->nullable()->after('from_email');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('email_templates')) {
            Schema::table('email_templates', function (Blueprint $table) {
                if (Schema::hasColumn('email_templates', 'from_email')) {
                    $table->dropColumn('from_email');
                }
                if (Schema::hasColumn('email_templates', 'from_name')) {
                    $table->dropColumn('from_name');
                }
            });
        }

        if (Schema::hasTable('email_logs')) {
            Schema::table('email_logs', function (Blueprint $table) {
                if (Schema::hasColumn('email_logs', 'from_email')) {
                    $table->dropColumn('from_email');
                }
                if (Schema::hasColumn('email_logs', 'from_name')) {
                    $table->dropColumn('from_name');
                }
            });
        }
    }
};
