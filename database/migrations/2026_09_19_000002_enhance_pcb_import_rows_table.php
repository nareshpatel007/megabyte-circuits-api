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
        Schema::table('pcb_imports', function (Blueprint $table) {
            if (!Schema::hasColumn('pcb_imports', 'valid_rows')) {
                $table->unsignedInteger('valid_rows')->default(0)->after('total_rows');
            }
            if (!Schema::hasColumn('pcb_imports', 'invalid_rows')) {
                $table->unsignedInteger('invalid_rows')->default(0)->after('valid_rows');
            }
        });

        Schema::table('pcb_import_rows', function (Blueprint $table) {
            if (!Schema::hasColumn('pcb_import_rows', 'row_data')) {
                $table->json('row_data')->nullable()->after('row_number');
            }
            if (!Schema::hasColumn('pcb_import_rows', 'validation_status')) {
                $table->string('validation_status', 30)->default('valid')->after('status');
            }
            if (!Schema::hasColumn('pcb_import_rows', 'validation_errors')) {
                $table->json('validation_errors')->nullable()->after('validation_status');
            }
            if (!Schema::hasColumn('pcb_import_rows', 'customer_action')) {
                $table->string('customer_action')->nullable()->after('customer_id');
            }
            if (!Schema::hasColumn('pcb_import_rows', 'resolved_customer_id')) {
                $table->unsignedBigInteger('resolved_customer_id')->nullable()->after('customer_action');
            }
            if (!Schema::hasColumn('pcb_import_rows', 'is_new_customer')) {
                $table->boolean('is_new_customer')->default(false)->after('resolved_customer_id');
            }
            if (!Schema::hasColumn('pcb_import_rows', 'is_duplicate')) {
                $table->boolean('is_duplicate')->default(false)->after('is_new_customer');
            }
            if (!Schema::hasColumn('pcb_import_rows', 'matched_order_number')) {
                $table->string('matched_order_number')->nullable()->after('is_duplicate');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pcb_imports', function (Blueprint $table) {
            $table->dropColumn(['valid_rows', 'invalid_rows']);
        });

        Schema::table('pcb_import_rows', function (Blueprint $table) {
            $table->dropColumn([
                'row_data',
                'validation_status',
                'validation_errors',
                'customer_action',
                'resolved_customer_id',
                'is_new_customer',
                'is_duplicate',
                'matched_order_number'
            ]);
        });
    }
};
