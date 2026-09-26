<?php

define('LARAVEL_START', microtime(true));

// Prevent script timeout for schedule tasks
ignore_user_abort(true);
set_time_limit(0);

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);

// Execute schedule:run command to run all scheduled tasks (including heartbeat)
$status = $kernel->call('schedule:run');
$output = $kernel->output();

// Ensure scheduler heartbeat cache key is explicitly recorded
\Illuminate\Support\Facades\Cache::put('system_health_scheduler_heartbeat', now()->timestamp, 86400);

header('Content-Type: text/plain');
echo "=== Scheduler Heartbeat & Schedule Execution ===\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";
echo $output ? $output : "Scheduler heartbeat updated successfully.\n";
