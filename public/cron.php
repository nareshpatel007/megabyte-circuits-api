<?php

define('LARAVEL_START', microtime(true));

// Prevent script timeout for long running sync tasks
ignore_user_abort(true);
set_time_limit(0);

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);

// Execute Laravel schedule:run command
$status = $kernel->call('schedule:run');
$output = $kernel->output();

header('Content-Type: text/plain');
echo "=== Laravel Cron Schedule Worker Execution ===\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";
echo $output ? $output : "No scheduled commands were due at this time.\n";
