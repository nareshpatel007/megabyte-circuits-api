<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\InventoryItem;
use App\Models\EmailTemplate;
use App\Models\EmailLog;
use App\Http\Controllers\InventoryController;
use Illuminate\Http\Request;

echo "=== STARTING INVENTORY EMAIL TEST SUITE ===\n\n";

// 1. Fetch or create a test item
$item = InventoryItem::firstOrCreate(
    ['sku' => 'TEST-PCB-EMAIL-01'],
    [
        'name' => 'Test High Quality PCB Board',
        'unit_price' => 150.00,
        'available_quantity' => 20,
        'low_stock_threshold' => 10,
        'status' => 'In Stock',
    ]
);

// Reset stock to 20
$item->available_quantity = 20;
$item->low_stock_threshold = 10;
$item->status = 'In Stock';
$item->save();

echo "Initial Item: ID={$item->id}, Stock={$item->available_quantity}, Threshold={$item->low_stock_threshold}\n";

$controller = new InventoryController();

// Clear existing logs for clean assertions
EmailLog::where('inventory_item_id', $item->id)->delete();

// TEST CASE 1: Low Stock Alert (20 -> 9)
echo "\n--- TEST CASE 1: Low Stock Transition (20 -> 9) ---\n";
$req1 = new Request(['type' => 'out', 'quantity' => 11, 'note' => 'Used for production batch #1']);
$controller->adjustStock($req1, $item->id);

$log1 = EmailLog::where('inventory_item_id', $item->id)->where('template_key', 'inventory_low_stock')->latest()->first();
echo $log1 ? "SUCCESS: Low Stock Email logged! Status: {$log1->status}, To: {$log1->to}\n" : "FAIL: Low Stock Email was not logged!\n";

// TEST CASE 2: Duplicate Low Stock Prevention (9 -> 8)
echo "\n--- TEST CASE 2: Duplicate Low Stock Prevention (9 -> 8) ---\n";
$countBefore = EmailLog::where('inventory_item_id', $item->id)->where('template_key', 'inventory_low_stock')->count();
$req2 = new Request(['type' => 'out', 'quantity' => 1, 'note' => 'Used for R&D']);
$controller->adjustStock($req2, $item->id);
$countAfter = EmailLog::where('inventory_item_id', $item->id)->where('template_key', 'inventory_low_stock')->count();

echo ($countAfter === $countBefore) ? "SUCCESS: Duplicate Low Stock Email prevented!\n" : "FAIL: Duplicate Low Stock Email was sent!\n";

// TEST CASE 3: Out of Stock Transition (8 -> 0)
echo "\n--- TEST CASE 3: Out of Stock Transition (8 -> 0) ---\n";
$req3 = new Request(['type' => 'out', 'quantity' => 8, 'note' => 'Emergency order fulfillment']);
$controller->adjustStock($req3, $item->id);

$log3 = EmailLog::where('inventory_item_id', $item->id)->where('template_key', 'inventory_out_of_stock')->latest()->first();
echo $log3 ? "SUCCESS: Out of Stock Email logged! Status: {$log3->status}, To: {$log3->to}\n" : "FAIL: Out of Stock Email was not logged!\n";

// TEST CASE 4: Duplicate Out of Stock Prevention (0 -> 0 update)
echo "\n--- TEST CASE 4: Duplicate Out of Stock Prevention (Update at 0 stock) ---\n";
$countOosBefore = EmailLog::where('inventory_item_id', $item->id)->where('template_key', 'inventory_out_of_stock')->count();
$req4 = new Request(['name' => 'Test High Quality PCB Board (Updated Name)', 'available_quantity' => 0]);
$controller->update($req4, $item->id);
$countOosAfter = EmailLog::where('inventory_item_id', $item->id)->where('template_key', 'inventory_out_of_stock')->count();

echo ($countOosAfter === $countOosBefore) ? "SUCCESS: Duplicate Out of Stock Email prevented!\n" : "FAIL: Duplicate Out of Stock Email was sent!\n";

// TEST CASE 5: Stock Added (0 -> 50)
echo "\n--- TEST CASE 5: Stock Added (0 -> 50) ---\n";
$req5 = new Request(['type' => 'in', 'quantity' => 50, 'note' => 'Restocked from manufacturer']);
$controller->adjustStock($req5, $item->id);

$log5 = EmailLog::where('inventory_item_id', $item->id)->where('template_key', 'inventory_stock_added')->latest()->first();
echo $log5 ? "SUCCESS: Stock Added Email logged! Status: {$log5->status}, To: {$log5->to}\n" : "FAIL: Stock Added Email was not logged!\n";

// TEST CASE 6: Inactive Template Test
echo "\n--- TEST CASE 6: Template Inactive Check ---\n";
$lowStockTpl = EmailTemplate::where('key', 'inventory_low_stock')->first();
if ($lowStockTpl) {
    $lowStockTpl->is_active = false;
    $lowStockTpl->save();
}

$job = new \App\Jobs\SendInventoryTemplateEmailJob('inventory_low_stock', $item->id);
$job->handle();

$log6 = EmailLog::where('inventory_item_id', $item->id)->where('template_key', 'inventory_low_stock')->orderBy('id', 'desc')->first();
echo ($log6 && $log6->status === 'skipped') ? "SUCCESS: Email skipped because template is inactive! Status: {$log6->status}, Error: {$log6->error_message}\n" : "FAIL: Inactive template status was '{$log6->status}' instead of 'skipped'\n";

// Re-enable template
if ($lowStockTpl) {
    $lowStockTpl->is_active = true;
    $lowStockTpl->save();
}

echo "\n=== ALL INVENTORY EMAIL TESTS COMPLETED ===\n";
