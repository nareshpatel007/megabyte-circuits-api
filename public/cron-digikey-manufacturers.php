<?php

define('LARAVEL_START', microtime(true));

// Prevent script timeout for long running sync tasks
ignore_user_abort(true);
set_time_limit(0);

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);

// Execute DigiKey manufacturers sync command directly
$status = $kernel->call('digikey:sync-manufacturers');
$output = $kernel->output();

header('Content-Type: text/plain');
echo "=== DigiKey Manufacturers Sync Execution ===\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";
echo $output ? $output : "Command executed successfully.\n";
