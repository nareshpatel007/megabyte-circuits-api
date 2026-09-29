<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('permissions')) {
            $perm = DB::table('permissions')->where('slug', 'orders.delete')->first();
            if (!$perm) {
                $permId = DB::table('permissions')->insertGetId([
                    'name' => 'Delete Orders',
                    'slug' => 'orders.delete',
                    'module' => 'Order Management',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $permId = $perm->id;
            }

            // Assign to Super Admin role (id = 1 or name = 'Super Admin') if role_permissions exists
            if (Schema::hasTable('role_permissions') && Schema::hasTable('roles')) {
                $superAdminRole = DB::table('roles')->where('id', 1)->orWhere('name', 'Super Admin')->first();
                if ($superAdminRole && $permId) {
                    $exists = DB::table('role_permissions')
                        ->where('role_id', $superAdminRole->id)
                        ->where('permission_id', $permId)
                        ->exists();

                    if (!$exists) {
                        DB::table('role_permissions')->insert([
                            'role_id' => $superAdminRole->id,
                            'permission_id' => $permId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
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
        if (Schema::hasTable('permissions')) {
            $perm = DB::table('permissions')->where('slug', 'orders.delete')->first();
            if ($perm) {
                if (Schema::hasTable('role_permissions')) {
                    DB::table('role_permissions')->where('permission_id', $perm->id)->delete();
                }
            }
        }
    }
};
