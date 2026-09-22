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
        Schema::table('gerber_files', function (Blueprint $table) {
            if (!Schema::hasColumn('gerber_files', 'status')) {
                $table->string('status', 50)->default('completed')->after('board_name');
            }
            if (!Schema::hasColumn('gerber_files', 'python_project_id')) {
                $table->string('python_project_id')->nullable()->after('status');
            }
            if (!Schema::hasColumn('gerber_files', 'board_width')) {
                $table->decimal('board_width', 10, 2)->nullable()->after('python_project_id');
            }
            if (!Schema::hasColumn('gerber_files', 'board_height')) {
                $table->decimal('board_height', 10, 2)->nullable()->after('board_width');
            }
            if (!Schema::hasColumn('gerber_files', 'layer_count')) {
                $table->integer('layer_count')->nullable()->after('board_height');
            }
            if (!Schema::hasColumn('gerber_files', 'front_preview_url')) {
                $table->text('front_preview_url')->nullable()->after('layer_count');
            }
            if (!Schema::hasColumn('gerber_files', 'back_preview_url')) {
                $table->text('back_preview_url')->nullable()->after('front_preview_url');
            }
            if (!Schema::hasColumn('gerber_files', 'analysis_data')) {
                $table->longText('analysis_data')->nullable()->after('back_preview_url');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gerber_files', function (Blueprint $table) {
            $columns = [
                'status',
                'python_project_id',
                'board_width',
                'board_height',
                'layer_count',
                'front_preview_url',
                'back_preview_url',
                'analysis_data'
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('gerber_files', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
