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
        if (!Schema::hasTable('pcb_imports')) {
            Schema::create('pcb_imports', function (Blueprint $table) {
                $table->id();
                $table->string('file_name');
                $table->string('original_file_name');
                $table->string('file_path');
                $table->string('file_type', 20)->default('xlsx');
                $table->unsignedBigInteger('file_size')->default(0);
                $table->string('status', 30)->default('queued'); // pending, queued, processing, completed, failed, cancelled
                $table->unsignedInteger('total_rows')->default(0);
                $table->unsignedInteger('processed_rows')->default(0);
                $table->unsignedInteger('successful_rows')->default(0);
                $table->unsignedInteger('failed_rows')->default(0);
                $table->unsignedInteger('skipped_rows')->default(0);
                $table->unsignedInteger('duplicate_rows')->default(0);
                $table->unsignedInteger('existing_customers')->default(0);
                $table->unsignedInteger('new_customers')->default(0);
                $table->string('duplicate_action', 30)->default('skip'); // skip, update, create_new
                $table->longText('error_message')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('pcb_import_errors')) {
            Schema::create('pcb_import_errors', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('import_id')->index();
                $table->unsignedInteger('row_number');
                $table->string('column_name')->nullable();
                $table->text('value')->nullable();
                $table->text('error_message');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('pcb_import_rows')) {
            Schema::create('pcb_import_rows', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('import_id')->index();
                $table->unsignedInteger('row_number');
                $table->string('status', 30)->default('pending'); // pending, processing, completed, failed, skipped, duplicate
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->unsignedBigInteger('order_id')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();

                $table->unique(['import_id', 'row_number']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pcb_import_rows');
        Schema::dropIfExists('pcb_import_errors');
        Schema::dropIfExists('pcb_imports');
    }
};
