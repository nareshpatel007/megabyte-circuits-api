<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('pcb_provider_rule_conditions')) {
            DB::table('pcb_provider_rule_conditions')
                ->whereIn('field', ['mark_on_pcb', 'elec_test'])
                ->delete();
        }

        Cache::forget('pcb_provider_rules_active');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No need to restore obsolete rules
    }
};
