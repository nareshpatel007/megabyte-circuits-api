<?php

define('LARAVEL_START', microtime(true));

// Prevent script timeout for cleanup tasks
ignore_user_abort(true);
set_time_limit(0);

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);

$options = [];

if (!empty($_GET['days']) && is_numeric($_GET['days'])) {
    $options['--days'] = (int)$_GET['days'];
}

$notificationOptions = $options;
if (isset($_GET['all']) && (filter_var($_GET['all'], FILTER_VALIDATE_BOOLEAN) || $_GET['all'] === '1' || $_GET['all'] === 'true')) {
    $notificationOptions['--all'] = true;
}

$type = $_GET['type'] ?? 'all';

header('Content-Type: text/plain');
echo "=== System Cleanup Execution (Email Logs, Notifications, Pending Registrations & Holidays) ===\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

if ($type === 'all' || $type === 'email' || $type === 'email-logs') {
    echo "--- Executing Email Logs Cleanup ---\n";
    $statusEmail = $kernel->call('email-logs:cleanup', $options);
    $outputEmail = $kernel->output();
    echo $outputEmail ? $outputEmail : "Email logs cleanup executed successfully.\n";
    echo "\n";
}

if ($type === 'all' || $type === 'notifications' || $type === 'notification') {
    echo "--- Executing Notifications Cleanup ---\n";
    $statusNotif = $kernel->call('notifications:cleanup', $notificationOptions);
    $outputNotif = $kernel->output();
    echo $outputNotif ? $outputNotif : "Notifications cleanup executed successfully.\n";
    echo "\n";
}

if ($type === 'all' || $type === 'pending-registrations' || $type === 'pending' || $type === 'registrations') {
    echo "--- Executing Pending Registrations Cleanup ---\n";
    $statusPending = $kernel->call('pending-registrations:cleanup');
    $outputPending = $kernel->output();
    echo $outputPending ? $outputPending : "Pending registrations cleanup executed successfully.\n";
    echo "\n";
}

if ($type === 'all' || $type === 'holidays' || $type === 'holiday') {
    echo "--- Executing Past Holidays Cleanup ---\n";
    $statusHolidays = $kernel->call('holidays:cleanup');
    $outputHolidays = $kernel->output();
    echo $outputHolidays ? $outputHolidays : "Past holidays cleanup executed successfully.\n";
    echo "\n";
}

echo "Cleanup process completed.\n";
