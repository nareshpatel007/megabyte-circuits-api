<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('impersonation_sessions')) {
            Schema::create('impersonation_sessions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('admin_id');
                $table->unsignedBigInteger('client_id');
                $table->string('token_hash')->nullable();
                $table->string('reason')->nullable();
                $table->string('status')->default('active'); // active, ended, expired
                $table->dateTime('started_at');
                $table->dateTime('expires_at');
                $table->dateTime('ended_at')->nullable();
                $table->string('ip_address')->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamps();

                $table->index('admin_id');
                $table->index('client_id');
                $table->index('status');
            });
        }

        if (!Schema::hasTable('impersonation_codes')) {
            Schema::create('impersonation_codes', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->unsignedBigInteger('admin_id');
                $table->unsignedBigInteger('client_id');
                $table->unsignedBigInteger('impersonation_session_id');
                $table->dateTime('expires_at');
                $table->boolean('used')->default(false);
                $table->timestamps();

                $table->index('code');
            });
        }

        // Ensure permission exists in permissions table
        if (Schema::hasTable('permissions')) {
            $perm = DB::table('permissions')->where('slug', 'clients.impersonate')->first();
            if (!$perm) {
                DB::table('permissions')->insert([
                    'name' => 'Login as Client (Impersonate)',
                    'slug' => 'clients.impersonate',
                    'module' => 'clients',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_codes');
        Schema::dropIfExists('impersonation_sessions');
    }
};
