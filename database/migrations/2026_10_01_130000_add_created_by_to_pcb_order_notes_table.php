<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pcb_order_notes')) {
            // Drop admin_name column if it was previously created
            if (Schema::hasColumn('pcb_order_notes', 'admin_name')) {
                Schema::table('pcb_order_notes', function (Blueprint $table) {
                    $table->dropColumn('admin_name');
                });
            }

            // Add created_by column to store admin id
            if (!Schema::hasColumn('pcb_order_notes', 'created_by')) {
                Schema::table('pcb_order_notes', function (Blueprint $table) {
                    $table->unsignedBigInteger('created_by')->nullable()->after('admin_id');
                });
            }

            // Backfill created_by from existing admin_id
            DB::statement("
                UPDATE pcb_order_notes 
                SET created_by = admin_id 
                WHERE created_by IS NULL AND admin_id IS NOT NULL AND admin_id > 0
            ");

            // For any remaining notes with null created_by, set to first admin in admins table
            if (Schema::hasTable('admins')) {
                $firstAdmin = DB::table('admins')->where('id', '>', 0)->orderBy('id')->first();
                if ($firstAdmin) {
                    DB::table('pcb_order_notes')
                        ->whereNull('created_by')
                        ->update([
                            'created_by' => $firstAdmin->id,
                            'admin_id' => $firstAdmin->id
                        ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pcb_order_notes') && Schema::hasColumn('pcb_order_notes', 'created_by')) {
            Schema::table('pcb_order_notes', function (Blueprint $table) {
                $table->dropColumn('created_by');
            });
        }
    }
};
