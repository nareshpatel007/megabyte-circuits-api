<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcb_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->integer('sort_order')->default(0);
            $table->string('color', 20)->default('#10b981');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Insert initial statuses
        $statuses = [
            'move',
            'Cam',
            'Cam done',
            'Filming',
            'Traveler',
            'Drilling',
            'Outside Drill',
            'Drill Done',
            'Blackhole',
            'DH',
            'DH Done',
            'DH Exposer',
            'Final Cutting',
            'Devloping',
            'Plating',
            'Plating QC',
            'Devloping QC',
            'Etching',
            'Etch QC',
            'Masking',
            'Masking Expose',
            'HAL/Tin',
            'Silk',
            'Vgroove',
            'Rout',
            'Rout Done',
            'Ready to ship',
            'BBT',
            'BBT-MQC',
            'Final QC',
            'Hold',
            'Merge',
            'FPT'
        ];

        $now = date('Y-m-d H:i:s');
        foreach ($statuses as $index => $statusName) {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $statusName), '-'));
            DB::table('pcb_statuses')->insert([
                'name' => $statusName,
                'slug' => $slug,
                'sort_order' => $index + 1,
                'color' => '#10b981',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pcb_statuses');
    }
};
