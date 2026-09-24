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
            if (!Schema::hasColumn('gerber_files', 'jlcpcb_file_key')) {
                $table->string('jlcpcb_file_key')->nullable()->after('analysis_data');
            }
            if (!Schema::hasColumn('gerber_files', 'jlcpcb_upload_status')) {
                $table->string('jlcpcb_upload_status', 50)->default('pending')->after('jlcpcb_file_key');
            }
            if (!Schema::hasColumn('gerber_files', 'jlcpcb_uploaded_at')) {
                $table->timestamp('jlcpcb_uploaded_at')->nullable()->after('jlcpcb_upload_status');
            }
            if (!Schema::hasColumn('gerber_files', 'jlcpcb_upload_error')) {
                $table->text('jlcpcb_upload_error')->nullable()->after('jlcpcb_uploaded_at');
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
                'jlcpcb_file_key',
                'jlcpcb_upload_status',
                'jlcpcb_uploaded_at',
                'jlcpcb_upload_error'
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('gerber_files', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
