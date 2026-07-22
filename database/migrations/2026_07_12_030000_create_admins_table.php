<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('status', 45)->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        // Insert default administrator account: admin@leadscraper360.com / password
        DB::table('admins')->insert([
            'name' => 'LeadScraper Admin',
            'email' => 'admin@leadscraper360.com',
            'password_hash' => password_hash('password', PASSWORD_BCRYPT),
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};
