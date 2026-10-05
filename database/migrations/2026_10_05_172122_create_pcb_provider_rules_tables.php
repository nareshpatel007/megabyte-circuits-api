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
        if (!Schema::hasTable('pcb_provider_rules')) {
            Schema::create('pcb_provider_rules', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('slug', 100)->unique();
                $table->string('provider', 50)->default('IN_HOUSE'); // IN_HOUSE, JLCPCB
                $table->integer('priority')->default(10); // Lower number = higher priority
                $table->string('match_type', 10)->default('ALL'); // ALL, ANY
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();

                $table->index(['is_active', 'priority', 'sort_order']);
            });
        }

        if (!Schema::hasTable('pcb_provider_rule_conditions')) {
            Schema::create('pcb_provider_rule_conditions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('rule_id')->constrained('pcb_provider_rules')->onDelete('cascade');
                $table->string('field', 100);
                $table->string('operator', 50)->default('equals');
                $table->json('value');
                $table->integer('sort_order')->default(0);
                $table->timestamps();

                $table->index(['rule_id', 'sort_order']);
                $table->index('field');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pcb_provider_rule_conditions');
        Schema::dropIfExists('pcb_provider_rules');
    }
};
