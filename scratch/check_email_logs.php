<?php

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require_once dirname(__DIR__) . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Total Email Logs: " . \App\Models\EmailLog::count() . PHP_EOL;
$logs = \App\Models\EmailLog::latest()->take(10)->get();
foreach ($logs as $log) {
    echo "ID: {$log->id} | Key: {$log->template_key} | Type: {$log->email_type} | To: {$log->to} | Subject: {$log->subject} | Status: {$log->status}\n";
}
