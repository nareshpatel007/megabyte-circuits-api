<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Admin;
use App\Models\PasswordResetOtp;
use App\Models\EmailTemplate;
use App\Services\PasswordResetService;
use App\Services\EmailTemplateService;
use App\Services\RegisterService;
use Illuminate\Support\Facades\DB;

echo "===========================================\n";
echo "AUTHENTICATION EMAIL SYSTEM INTEGRATION TEST\n";
echo "===========================================\n\n";

$passedCount = 0;
$failedCount = 0;

function assertTest($condition, $testName) {
    global $passedCount, $failedCount;
    if ($condition) {
        echo "  [PASS] {$testName}\n";
        $passedCount++;
    } else {
        echo "  [FAIL] {$testName}\n";
        $failedCount++;
    }
}

// 1. Verify Templates in Database
echo "1. Testing Email Templates Database Seeds...\n";
$otpTpl = EmailTemplateService::findTemplate('password_reset_otp');
assertTest($otpTpl && $otpTpl->is_active, "password_reset_otp template exists and is active");

$successTpl = EmailTemplateService::findTemplate('password_reset_success');
assertTest($successTpl && $successTpl->is_active, "password_reset_success template exists and is active");

$welcomeTpl = EmailTemplateService::findTemplate('client_welcome');
assertTest($welcomeTpl && $welcomeTpl->is_active, "client_welcome template exists and is active");

// 2. Test Client Forgot Password Flow
echo "\n2. Testing Client Forgot Password & OTP Flow...\n";

// Ensure a test user exists
$testEmail = 'testclient_' . time() . '@example.com';
$userObj = DB::table('users')->where('email', $testEmail)->first();
if (!$userObj) {
    $userId = DB::table('users')->insertGetId([
        'name' => 'Test Client User',
        'email' => $testEmail,
        'password' => password_hash('OldPassword123!', PASSWORD_BCRYPT),
        'password_hash' => password_hash('OldPassword123!', PASSWORD_BCRYPT),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
} else {
    $userId = $userObj->id;
}

// Request OTP for Client
$reqRes = PasswordResetService::requestOtp($testEmail, 'client', '127.0.0.1', 'TestRunner');
assertTest($reqRes['success'] === true, "Client requested OTP successfully");
assertTest(!empty($reqRes['masked_email']), "Masked email returned in request OTP response");

// Retrieve generated OTP record directly from DB to verify
$otpRecord = PasswordResetOtp::where('user_id', $userId)->whereNull('used_at')->orderBy('created_at', 'desc')->first();
assertTest(!empty($otpRecord), "OTP record saved in password_reset_otps table");
assertTest($otpRecord->attempts === 0, "Initial attempts count is 0");
assertTest($otpRecord->expires_at->gt(now()), "OTP expiration set in future");

// Test Invalid OTP Submission
$invalidVer = PasswordResetService::verifyOtp($testEmail, '000000', 'client');
assertTest($invalidVer['success'] === false, "Invalid OTP rejected properly");

$otpRecordRefresh = PasswordResetOtp::find($otpRecord->id);
assertTest($otpRecordRefresh->attempts === 1, "OTP attempts incremented after failed attempt");

// Test Account check for unknown email
$unknownReq = PasswordResetService::requestOtp('nonexistent_' . time() . '@example.com', 'client');
assertTest($unknownReq['success'] === false, "Unknown email returns error: Account does not exist");

// Create valid OTP for testing verification and reset
$validOtp = '123456';
$validOtpHash = hash('sha256', $validOtp);
$otpRecordRefresh->update([
    'otp_hash' => $validOtpHash,
    'attempts' => 0,
    'expires_at' => now()->addMinutes(10),
]);

// Verify Valid OTP
$verRes = PasswordResetService::verifyOtp($testEmail, $validOtp, 'client');
assertTest($verRes['success'] === true, "Valid 6-digit OTP verified successfully");
assertTest(!empty($verRes['reset_token']), "Server-side reset authorization token issued upon OTP verification");

$resetToken = $verRes['reset_token'];

// Test Password Reset with invalid complexity
$invalidComplexity = PasswordResetService::resetPassword($resetToken, 'simple', 'simple', 'client');
assertTest($invalidComplexity['success'] === false, "Weak password rejected by complexity validation");

// Test Password Reset with valid complexity
$validNewPassword = 'NewSecretPassword123!';
$resetRes = PasswordResetService::resetPassword($resetToken, $validNewPassword, $validNewPassword, 'client');
assertTest($resetRes['success'] === true, "Password updated successfully using reset token");

// Verify single-use token protection
$reusedToken = PasswordResetService::resetPassword($resetToken, 'AnotherPassword123!', 'AnotherPassword123!', 'client');
assertTest($reusedToken['success'] === false, "Reset token cannot be reused after password update");

// 3. Test Admin Forgot Password Flow
echo "\n3. Testing Admin Forgot Password & OTP Flow...\n";

$adminEmail = 'testadmin_' . time() . '@example.com';
$adminId = DB::table('admins')->insertGetId([
    'name' => 'Test Admin User',
    'username' => 'testadmin_' . time(),
    'email' => $adminEmail,
    'password_hash' => password_hash('AdminPass123!', PASSWORD_BCRYPT),
    'status' => 'active',
    'created_at' => now(),
    'updated_at' => now(),
]);

$adminReq = PasswordResetService::requestOtp($adminEmail, 'admin', '127.0.0.1', 'TestRunner');
assertTest($adminReq['success'] === true, "Admin requested OTP successfully");

$adminOtpRec = PasswordResetOtp::where('admin_id', $adminId)->whereNull('used_at')->orderBy('created_at', 'desc')->first();
assertTest(!empty($adminOtpRec), "Admin OTP record created");

// Update OTP to known value for verification test
$adminOtp = '654321';
$adminOtpRec->update([
    'otp_hash' => hash('sha256', $adminOtp),
    'attempts' => 0,
    'expires_at' => now()->addMinutes(10),
]);

$adminVer = PasswordResetService::verifyOtp($adminEmail, $adminOtp, 'admin');
assertTest($adminVer['success'] === true, "Admin OTP verified successfully");

$adminResetToken = $adminVer['reset_token'];
$adminReset = PasswordResetService::resetPassword($adminResetToken, 'NewAdminPass123!', 'NewAdminPass123!', 'admin');
assertTest($adminReset['success'] === true, "Admin password reset successfully");

// 4. Test New Client Registration & Welcome Email
echo "\n4. Testing New Client Registration Welcome Email...\n";

$registerService = new RegisterService();
$regEmail = 'newclient_' . time() . '@example.com';
$regRes = $registerService->register([
    'username' => 'newclient_' . time(),
    'name' => 'New Registered Client',
    'email' => $regEmail,
    'password' => 'SecurePass123!',
]);

assertTest($regRes['status'] === true, "New client registration completed successfully");

// 5. Test Email Log Entries
echo "\n5. Testing Email Logs Table Entries...\n";

$otpLog = \App\Models\EmailLog::where('template_key', 'password_reset_otp')->where('to', $testEmail)->first();
assertTest(!empty($otpLog), "password_reset_otp logged in email_logs table");

$resetLog = \App\Models\EmailLog::where('template_key', 'password_reset_success')->where('to', $testEmail)->first();
assertTest(!empty($resetLog), "password_reset_success logged in email_logs table");

$welcomeLog = \App\Models\EmailLog::where('template_key', 'client_welcome')->where('to', $regEmail)->first();
assertTest(!empty($welcomeLog), "client_welcome logged in email_logs table");

// Cleanup test records
DB::table('users')->where('email', $testEmail)->orWhere('email', $regEmail)->delete();
DB::table('admins')->where('id', $adminId)->delete();
DB::table('password_reset_otps')->where('identifier', $testEmail)->orWhere('identifier', $adminEmail)->delete();
DB::table('email_logs')->whereIn('to', [$testEmail, $adminEmail, $regEmail])->delete();

echo "\n===========================================\n";
echo "TEST RESULTS: {$passedCount} Passed, {$failedCount} Failed.\n";
echo "===========================================\n";
