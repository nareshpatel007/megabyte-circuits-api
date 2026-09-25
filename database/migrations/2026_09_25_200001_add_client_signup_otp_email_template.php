<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::table('email_templates')->where('key', 'client_signup_otp')->doesntExist()) {
            DB::table('email_templates')->insert([
                'key'        => 'client_signup_otp',
                'name'       => 'Client Signup Verification OTP',
                'subject'    => 'Verify your Megabyte account',
                'body'       => '<h2 style="color: #10b981; font-size: 22px; font-weight: 700; margin-top: 0; margin-bottom: 16px;">Verify Your Email Address</h2>
<p>Hello <strong>{{name}}</strong>,</p>
<p>Thank you for signing up with <strong>Megabyte Circuits</strong>.</p>
<p>Your verification code is:</p>
<div style="text-align: center; margin: 24px 0;">
    <span style="font-size: 32px; font-weight: 800; letter-spacing: 6px; color: #10b981; background: #f0fdf4; padding: 12px 28px; border-radius: 8px; border: 1px border-dashed #a7f3d0; display: inline-block;">{{otp}}</span>
</div>
<p>This code will expire in <strong>{{otp_expiry_minutes}}</strong> minutes.</p>
<p>Enter this code in the registration screen to complete your account creation.</p>
<p>If you did not request this account, you can safely ignore this email.</p>
<p>Regards,<br><strong>Megabyte Circuits</strong></p>',
                'to'         => '{{email}}',
                'from_email' => null,
                'from_name'  => '{{company_name}}',
                'cc'         => null,
                'bcc'        => null,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('email_templates')->where('key', 'client_signup_otp')->delete();
    }
};
