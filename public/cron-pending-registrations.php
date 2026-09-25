<?php

define('LARAVEL_START', microtime(true));

// Prevent script timeout for long running cleanup tasks
ignore_user_abort(true);
set_time_limit(0);

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);

// Execute pending-registrations:cleanup command directly
$status = $kernel->call('pending-registrations:cleanup');
$output = $kernel->output();

header('Content-Type: text/plain');
echo "=== Pending Registrations Cleanup Execution ===\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";
echo $output ? $output : "Pending registrations cleanup command executed successfully.\n";
