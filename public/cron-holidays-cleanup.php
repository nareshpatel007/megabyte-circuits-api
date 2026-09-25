<?php

define('LARAVEL_START', microtime(true));

// Prevent script timeout for cleanup tasks
ignore_user_abort(true);
set_time_limit(0);

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);

// Execute holidays:cleanup command directly
$status = $kernel->call('holidays:cleanup');
$output = $kernel->output();

header('Content-Type: text/plain');
echo "=== Past Holidays Cleanup Execution ===\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";
echo $output ? $output : "Past holidays cleanup command executed successfully.\n";
