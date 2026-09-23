<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== CREDENTIALS TABLE == \n";
$creds = DB::table('credentials')->get();
foreach ($creds as $c) {
    echo "Group: {$c->group} | Key: {$c->key} | Value: {$c->value}\n";
}
