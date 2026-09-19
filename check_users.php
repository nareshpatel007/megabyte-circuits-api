<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();
$columns = Illuminate\Support\Facades\Schema::getColumnListing('users');
echo "USERS COLUMNS:\n";
print_r($columns);
