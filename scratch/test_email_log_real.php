<?php

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require_once dirname(__DIR__) . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\PasswordResetService;
use App\Models\EmailLog;
use App\Models\Admin;
use App\Models\User;

echo "--- Testing OTP Email & Log ---\n";
$user = User::first();
if (!$user) {
    $user = User::create([
        'username' => 'testuser_' . time(),
        'name' => 'Test User',
        'email' => 'testuser_' . time() . '@example.com',
        'password' => bcrypt('Password123!'),
        'status' => 'active',
    ]);
}

echo "Testing OTP request for User email: {$user->email}\n";
$res = PasswordResetService::requestOtp($user->email, 'client', '127.0.0.1', 'PHPTestRunner');
echo "Request OTP Response: " . json_encode($res) . "\n";

$latestLog = EmailLog::latest()->first();
if ($latestLog) {
    echo "LATEST EMAIL LOG FOUND:\n";
    echo "ID: {$latestLog->id}\n";
    echo "Template Key: {$latestLog->template_key}\n";
    echo "Email Type: {$latestLog->email_type}\n";
    echo "To: {$latestLog->to}\n";
    echo "From: {$latestLog->from_email}\n";
    echo "Subject: {$latestLog->subject}\n";
    echo "Status: {$latestLog->status}\n";
    echo "Error: {$latestLog->error_message}\n";
} else {
    echo "NO EMAIL LOG RECORDED!\n";
}
