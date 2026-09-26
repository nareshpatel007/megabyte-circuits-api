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
        Schema::create('job_card_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pcb_order_id')->constrained('pcb_orders')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('file_path');
            $table->string('mime_type');
            $table->string('file_type'); // pdf, doc, docx
            $table->string('source_type'); // uploaded_pdf, uploaded_doc, uploaded_docx, converted_pdf
            $table->string('converted_pdf_path')->nullable();
            $table->string('converted_pdf_name')->nullable();
            $table->integer('page_count')->default(0);
            $table->unsignedBigInteger('file_size')->default(0);
            $table->integer('sort_order')->default(1);
            $table->string('status')->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['pcb_order_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_card_documents');
    }
};
