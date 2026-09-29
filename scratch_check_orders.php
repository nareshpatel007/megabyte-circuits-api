<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$rows = \Illuminate\Support\Facades\DB::table('pcb_orders')->get();
foreach ($rows as $r) {
    echo "ID: {$r->id} | Order#: {$r->order_number} | Status: '{$r->status}' | status_id: '{$r->status_id}' | deleted_at: " . ($r->deleted_at ?? 'NULL') . "\n";
}
