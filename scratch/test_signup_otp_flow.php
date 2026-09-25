<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\PendingRegistration;
use App\Models\EmailTemplate;
use App\Models\EmailLog;
use App\Services\RegisterService;
use App\Services\EmailTemplateService;
use Illuminate\Support\Facades\DB;

echo "===========================================\n";
echo "CLIENT SIGNUP EMAIL OTP INTEGRATION TEST\n";
echo "===========================================\n\n";

$passed = 0;
$failed = 0;

function assertCheck($cond, $msg) {
    global $passed, $failed;
    if ($cond) {
        echo "  [PASS] {$msg}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$msg}\n";
        $failed++;
    }
}

// 1. Template Verification
echo "1. Verifying Email Templates in Database...\n";
$signupOtpTpl = EmailTemplateService::findTemplate('client_signup_otp');
assertCheck(!empty($signupOtpTpl) && $signupOtpTpl->is_active, "CLIENT_SIGNUP_OTP template exists and is active");

$welcomeTpl = EmailTemplateService::findTemplate('client_welcome');
assertCheck(!empty($welcomeTpl) && $welcomeTpl->is_active, "CLIENT_WELCOME template exists and is active");


// 2. Initiate Registration Test
echo "\n2. Testing Registration Initiation (No User Creation Before OTP Verification)...\n";

$testEmail = 'otp_test_' . time() . '@example.com';
$testPassword = 'TestPassword123!';
$testName = 'OTP Test Client';

$registerService = new RegisterService();

$initRes = $registerService->initiateRegistration([
    'username'     => 'otptest' . time(),
    'name'         => $testName,
    'email'        => $testEmail,
    'password'     => $testPassword,
    'company_name' => 'Megabyte Test Corp',
    'country'      => 'India',
    'gst_number'   => '29ABCDE1234F1Z5'
], '127.0.0.1', 'PHPUnitTestRunner');

assertCheck($initRes['status'] === true && $initRes['success'] === true, "Initiate signup returns success status");
assertCheck(!empty($initRes['registration_token']), "Initiate signup returns registration_token");

// Verify User is NOT created yet in database
$userInDb = DB::table('users')->where('email', $testEmail)->first();
assertCheck(empty($userInDb), "User account is NOT created in users table prior to OTP verification");

// Verify Pending Registration record exists
$pendingHash = hash('sha256', $initRes['registration_token']);
$pending = PendingRegistration::where('registration_token_hash', $pendingHash)->first();
assertCheck(!empty($pending), "Pending registration record saved in pending_registrations table");
assertCheck($pending->email === $testEmail, "Pending record has matching email");
assertCheck(!empty($pending->password_hash) && $pending->password_hash !== $testPassword, "Password stored as secure hash, not plain text");
assertCheck(!empty($pending->otp_hash), "OTP stored as secure hash, not plain text");

// 3. Invalid OTP Submission Test
echo "\n3. Testing Invalid OTP Submission...\n";
$invalidRes = $registerService->verifyOtpAndCreateUser($initRes['registration_token'], '000000');
assertCheck($invalidRes['status'] === false, "Invalid OTP is rejected");
assertCheck(str_contains(strtolower($invalidRes['message']), 'invalid'), "Error message contains 'Invalid verification code'");

$userStillDb = DB::table('users')->where('email', $testEmail)->first();
assertCheck(empty($userStillDb), "User account remains uncreated after failed OTP attempt");

// 4. Resend OTP Cooldown & Regeneration Test
echo "\n4. Testing Resend OTP Cooldown...\n";
$resendCool = $registerService->resendOtp($initRes['registration_token']);
assertCheck($resendCool['status'] === false, "Resend within 60s cooldown is rate limited");

// Force cooldown pass for testing
$pending->update(['last_otp_sent_at' => now()->subSeconds(65)]);
$resendRes = $registerService->resendOtp($initRes['registration_token']);
assertCheck($resendRes['status'] === true, "Resend succeeds after cooldown");

// 5. Valid OTP Verification & Account Creation Test
echo "\n5. Testing Valid OTP Verification & Account Creation...\n";

// Generate known OTP for testing verification logic directly
$testOtp = '654321';
$pendingFresh = PendingRegistration::where('registration_token_hash', $pendingHash)->first();
$pendingFresh->update([
    'otp_hash'       => hash('sha256', $testOtp),
    'otp_attempts'   => 0,
    'otp_expires_at' => now()->addMinutes(10),
]);

$verifyRes = $registerService->verifyOtpAndCreateUser($initRes['registration_token'], $testOtp);
if (!($verifyRes['status'] ?? false)) { var_dump($verifyRes); }
assertCheck($verifyRes['status'] === true && $verifyRes['success'] === true, "Valid OTP verification succeeds");
assertCheck(!empty($verifyRes['data']['access_token']), "Returns access_token for automatic login");

$createdUser = DB::table('users')->where('email', $testEmail)->first();
assertCheck(!empty($createdUser), "User account successfully created in users table after OTP verification");
assertCheck((int)$createdUser->available_credits === 50, "User granted 50 free credits upon registration");

$pendingUsed = PendingRegistration::where('registration_token_hash', $pendingHash)->first();
assertCheck(!empty($pendingUsed->used_at) && !empty($pendingUsed->verified_at), "Pending registration marked as used and verified");

// Check Welcome Email logging
$welcomeLog = EmailLog::where('template_key', 'client_welcome')->where('to', $testEmail)->first();
assertCheck(!empty($welcomeLog), "Welcome email recorded in email_logs table");

// 6. Duplicate Email Registration Test
echo "\n6. Testing Duplicate Email Signup Protection...\n";
$dupeRes = $registerService->initiateRegistration([
    'name'     => 'Dupe User',
    'email'    => $testEmail,
    'password' => 'Password123!'
]);
assertCheck($dupeRes['status'] === false, "Existing registered email signup is rejected");
assertCheck(str_contains(strtolower($dupeRes['message']), 'already exists'), "Returns 'An account with this email address already exists'");

echo "\n===========================================\n";
echo "SUMMARY: {$passed} PASSED, {$failed} FAILED\n";
echo "===========================================\n";

if ($failed > 0) {
    exit(1);
}
