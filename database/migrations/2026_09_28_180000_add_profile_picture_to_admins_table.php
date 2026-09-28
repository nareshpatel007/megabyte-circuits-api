<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admins')) {
            Schema::table('admins', function (Blueprint $table) {
                if (!Schema::hasColumn('admins', 'profile_picture')) {
                    $table->string('profile_picture', 500)->nullable()->after('email');
                }
                if (!Schema::hasColumn('admins', 'mobile')) {
                    $table->string('mobile', 45)->nullable()->after('email');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('admins')) {
            Schema::table('admins', function (Blueprint $table) {
                if (Schema::hasColumn('admins', 'profile_picture')) {
                    $table->dropColumn('profile_picture');
                }
            });
        }
    }
};
