<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$migration = require __DIR__ . '/database/migrations/2026_09_26_210000_create_impersonation_tables.php';
$migration->up();
echo "IMPERSONATION TABLES CREATED SUCCESSFULLY\n";
