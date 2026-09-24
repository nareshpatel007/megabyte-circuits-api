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
        if (Schema::hasTable('email_logs')) {
            Schema::table('email_logs', function (Blueprint $table) {
                if (!Schema::hasColumn('email_logs', 'email_type')) {
                    $table->string('email_type')->nullable()->after('template_key')->index();
                }
                if (!Schema::hasColumn('email_logs', 'reply_to')) {
                    $table->string('reply_to')->nullable()->after('from_name');
                }
                if (!Schema::hasColumn('email_logs', 'body')) {
                    $table->longText('body')->nullable()->after('subject');
                }
                if (!Schema::hasColumn('email_logs', 'text_body')) {
                    $table->longText('text_body')->nullable()->after('body');
                }
                if (!Schema::hasColumn('email_logs', 'provider')) {
                    $table->string('provider')->nullable()->after('status');
                }
                if (!Schema::hasColumn('email_logs', 'provider_message_id')) {
                    $table->string('provider_message_id')->nullable()->after('provider');
                }
                if (!Schema::hasColumn('email_logs', 'job_id')) {
                    $table->string('job_id')->nullable()->after('provider_message_id');
                }
                if (!Schema::hasColumn('email_logs', 'retry_count')) {
                    $table->integer('retry_count')->default(0)->after('job_id');
                }
                if (!Schema::hasColumn('email_logs', 'failed_at')) {
                    $table->timestamp('failed_at')->nullable()->after('sent_at');
                }
                if (!Schema::hasColumn('email_logs', 'metadata')) {
                    $table->json('metadata')->nullable()->after('failed_at');
                }
            });

            // Modify status enum/column if needed by ensuring string column for flexible status values (sent, failed, queued, processing, skipped, bounced)
            // Ensure indexes exist for faster filtering
            try {
                Schema::table('email_logs', function (Blueprint $table) {
                    $table->index('status', 'email_logs_status_index');
                    $table->index('created_at', 'email_logs_created_at_index');
                    $table->index('to', 'email_logs_to_index');
                });
            } catch (\Throwable $e) {
                // Ignore if index already exists
            }
        }

        // Add email logs permissions
        if (Schema::hasTable('permissions')) {
            $perms = [
                ['name' => 'View Email Logs', 'slug' => 'email_logs.view', 'module' => 'Settings'],
                ['name' => 'View Email Log Details', 'slug' => 'email_logs.view_details', 'module' => 'Settings'],
                ['name' => 'Delete Email Logs', 'slug' => 'email_logs.delete', 'module' => 'Settings'],
                ['name' => 'Manual Email Logs Cleanup', 'slug' => 'email_logs.cleanup', 'module' => 'Settings'],
                ['name' => 'Export Email Logs', 'slug' => 'email_logs.export', 'module' => 'Settings'],
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
        if (Schema::hasTable('email_logs')) {
            Schema::table('email_logs', function (Blueprint $table) {
                if (Schema::hasColumn('email_logs', 'email_type')) $table->dropColumn('email_type');
                if (Schema::hasColumn('email_logs', 'reply_to')) $table->dropColumn('reply_to');
                if (Schema::hasColumn('email_logs', 'body')) $table->dropColumn('body');
                if (Schema::hasColumn('email_logs', 'text_body')) $table->dropColumn('text_body');
                if (Schema::hasColumn('email_logs', 'provider')) $table->dropColumn('provider');
                if (Schema::hasColumn('email_logs', 'provider_message_id')) $table->dropColumn('provider_message_id');
                if (Schema::hasColumn('email_logs', 'job_id')) $table->dropColumn('job_id');
                if (Schema::hasColumn('email_logs', 'retry_count')) $table->dropColumn('retry_count');
                if (Schema::hasColumn('email_logs', 'failed_at')) $table->dropColumn('failed_at');
                if (Schema::hasColumn('email_logs', 'metadata')) $table->dropColumn('metadata');
            });
        }
    }
};
