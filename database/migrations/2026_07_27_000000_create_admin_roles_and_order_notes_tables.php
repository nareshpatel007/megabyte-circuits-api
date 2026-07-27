<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add username & role_id to admins table if not present
        if (Schema::hasTable('admins')) {
            Schema::table('admins', function (Blueprint $table) {
                if (!Schema::hasColumn('admins', 'username')) {
                    $table->string('username')->nullable()->unique()->after('name');
                }
                if (!Schema::hasColumn('admins', 'role_id')) {
                    $table->unsignedBigInteger('role_id')->nullable()->after('status');
                }
            });

            // Set default username for existing admin
            DB::table('admins')->whereNull('username')->orWhere('username', '')->update([
                'username' => 'admin'
            ]);
        }

        // 2. Create Roles table
        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->timestamps();
            });

            // Seed default roles
            DB::table('roles')->insert([
                [
                    'id' => 1,
                    'name' => 'Super Admin',
                    'slug' => 'super-admin',
                    'description' => 'Full control over all administrative modules and settings',
                    'created_at' => now(),
                    'updated_at' => now()
                ],
                [
                    'id' => 2,
                    'name' => 'Production Manager',
                    'slug' => 'production-manager',
                    'description' => 'Manages order processing, status updates, and Gerber file downloads',
                    'created_at' => now(),
                    'updated_at' => now()
                ],
                [
                    'id' => 3,
                    'name' => 'Sales & Support',
                    'slug' => 'sales-support',
                    'description' => 'Views orders, customer information, and adds order notes',
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            ]);

            // Assign Super Admin role to default admin
            DB::table('admins')->where('id', 1)->update(['role_id' => 1]);
        }

        // 3. Create Permissions table
        if (!Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('module');
                $table->timestamps();
            });

            $defaultPermissions = [
                ['name' => 'View Orders', 'slug' => 'orders.view', 'module' => 'Orders'],
                ['name' => 'Manage Orders', 'slug' => 'orders.manage', 'module' => 'Orders'],
                ['name' => 'View Gerber Files', 'slug' => 'orders.view_gerber', 'module' => 'Orders'],
                ['name' => 'Update Order Status', 'slug' => 'orders.update_status', 'module' => 'Orders'],
                ['name' => 'Manage Order Notes', 'slug' => 'orders.notes', 'module' => 'Orders'],
                ['name' => 'Manage Users', 'slug' => 'users.manage', 'module' => 'User Management'],
                ['name' => 'Manage Roles & Permissions', 'slug' => 'roles.manage', 'module' => 'User Management'],
                ['name' => 'Manage Pipeline Statuses', 'slug' => 'statuses.manage', 'module' => 'Settings'],
            ];

            foreach ($defaultPermissions as $p) {
                DB::table('permissions')->insert(array_merge($p, ['created_at' => now(), 'updated_at' => now()]));
            }
        }

        // 4. Create Role Permissions table
        if (!Schema::hasTable('role_permissions')) {
            Schema::create('role_permissions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('role_id');
                $table->unsignedBigInteger('permission_id');
                $table->timestamps();

                $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
                $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');
            });

            // Assign all permissions to Super Admin role (id=1)
            $allPermIds = DB::table('permissions')->pluck('id');
            foreach ($allPermIds as $pId) {
                DB::table('role_permissions')->insert([
                    'role_id' => 1,
                    'permission_id' => $pId,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }
        }

        // 5. Create PCB Order Notes table
        if (!Schema::hasTable('pcb_order_notes')) {
            Schema::create('pcb_order_notes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('pcb_order_id');
                $table->unsignedBigInteger('admin_id')->nullable();
                $table->text('note');
                $table->boolean('is_internal')->default(true);
                $table->timestamps();

                $table->foreign('pcb_order_id')->references('id')->on('pcb_orders')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pcb_order_notes');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');

        if (Schema::hasTable('admins')) {
            Schema::table('admins', function (Blueprint $table) {
                if (Schema::hasColumn('admins', 'username')) {
                    $table->dropColumn('username');
                }
                if (Schema::hasColumn('admins', 'role_id')) {
                    $table->dropColumn('role_id');
                }
            });
        }
    }
};
