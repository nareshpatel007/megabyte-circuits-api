<?php

define('LARAVEL_START', microtime(true));

// Prevent script timeout for long running sync tasks
ignore_user_abort(true);
set_time_limit(0);

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);

$task = $_GET['task'] ?? 'schedule';

$commands = [
    'schedule'            => 'schedule:run',
    'gerber-clean'        => 'gerber:clean-unattached',
    'digikey-sync'        => 'digikey:sync',
    'digikey-manufacturers' => 'digikey:sync-manufacturers',
    'digikey-categories'  => 'digikey:sync-categories',
];

$commandToRun = $commands[$task] ?? 'schedule:run';

$status = $kernel->call($commandToRun);
$output = $kernel->output();

header('Content-Type: text/plain');
echo "=== Laravel Cron Execution ({$commandToRun}) ===\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";
echo $output ? $output : "Command executed successfully with no additional output.\n";
