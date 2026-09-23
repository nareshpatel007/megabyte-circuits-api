<?php

define('LARAVEL_START', microtime(true));

// Prevent script timeout for daily report tasks
ignore_user_abort(true);
set_time_limit(0);

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);

$options = [];

if (!empty($_GET['date'])) {
    $options['--date'] = trim($_GET['date']);
}

if (isset($_GET['force']) && (filter_var($_GET['force'], FILTER_VALIDATE_BOOLEAN) || $_GET['force'] === '1' || $_GET['force'] === 'true')) {
    $options['--force'] = true;
}

// Execute Daily Reports command directly
$status = $kernel->call('reports:send-daily', $options);
$output = $kernel->output();

header('Content-Type: text/plain');
echo "=== Daily Email Reports Execution ===\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";
echo $output ? $output : "Daily report command executed successfully.\n";
