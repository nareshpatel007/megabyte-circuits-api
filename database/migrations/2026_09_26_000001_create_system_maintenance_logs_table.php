<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('system_maintenance_logs')) {
            Schema::create('system_maintenance_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('admin_id')->nullable()->index();
                $table->string('admin_name')->nullable();
                $table->string('action')->index();
                $table->string('target_system')->nullable();
                $table->string('status')->default('success')->index(); // success, failed
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->integer('duration_ms')->default(0);
                $table->string('ip_address')->nullable();
                $table->text('user_agent')->nullable();
                $table->text('error_message')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        // Register system health permissions
        if (Schema::hasTable('permissions')) {
            $perms = [
                ['name' => 'View System Health', 'slug' => 'system_health.view', 'module' => 'Administration'],
                ['name' => 'Manage System Health', 'slug' => 'system_health.manage', 'module' => 'Administration'],
                ['name' => 'Execute Maintenance Operations', 'slug' => 'system_health.maintenance', 'module' => 'Administration'],
                ['name' => 'View System Logs & Audit', 'slug' => 'system_health.logs', 'module' => 'Administration'],
            ];

            foreach ($perms as $p) {
                $exists = DB::table('permissions')->where('slug', $p['slug'])->first();
                if (!$exists) {
                    $id = DB::table('permissions')->insertGetId(array_merge($p, [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]));

                    // Assign to Super Admin role (role_id = 1) if role_permissions table exists
                    if (Schema::hasTable('role_permissions')) {
                        $rpExists = DB::table('role_permissions')
                            ->where('role_id', 1)
                            ->where('permission_id', $id)
                            ->first();
                        if (!$rpExists) {
                            DB::table('role_permissions')->insert([
                                'role_id' => 1,
                                'permission_id' => $id,
                                'created_at' => now(),
                                'updated_at' => now()
                            ]);
                        }
                    }
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_maintenance_logs');
    }
};
