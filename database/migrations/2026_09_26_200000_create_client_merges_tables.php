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
        if (!Schema::hasTable('client_merges')) {
            Schema::create('client_merges', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('target_user_id');
                $table->unsignedBigInteger('performed_by')->nullable();
                $table->string('performed_by_name')->nullable();
                $table->integer('source_clients_count')->default(0);
                $table->integer('orders_migrated')->default(0);
                $table->integer('payments_migrated')->default(0);
                $table->integer('addresses_migrated')->default(0);
                $table->integer('gerbers_migrated')->default(0);
                $table->integer('tickets_migrated')->default(0);
                $table->json('conflict_resolutions')->nullable();
                $table->json('summary')->nullable();
                $table->timestamps();

                $table->index('target_user_id');
                $table->index('performed_by');
            });
        }

        if (!Schema::hasTable('client_merge_items')) {
            Schema::create('client_merge_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_merge_id')->constrained('client_merges')->cascadeOnDelete();
                $table->unsignedBigInteger('source_user_id');
                $table->string('source_name');
                $table->string('source_email');
                $table->string('source_company')->nullable();
                $table->json('migrated_stats')->nullable();
                $table->timestamps();

                $table->index('source_user_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_merge_items');
        Schema::dropIfExists('client_merges');
    }
};
