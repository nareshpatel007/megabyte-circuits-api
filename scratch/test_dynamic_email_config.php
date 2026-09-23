<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\EmailTemplate;
use App\Models\EmailLog;
use App\Services\EmailTemplateService;

echo "=== STARTING DYNAMIC EMAIL CONFIGURATION TEST SUITE ===\n\n";

// TEST CASE 1: Dynamic TO, FROM, CC, BCC, Subject resolution for Order Placed
echo "--- TEST CASE 1: Order Placed Dynamic Variable Resolution ---\n";
$tpl1 = EmailTemplate::where('key', 'order_placed')->first();
$tpl1->to = '{{customer_email}}, orders@megabytecircuit.com';
$tpl1->from_email = 'notifications@megabytecircuit.com';
$tpl1->from_name = '{{company_name}} Orders Dept';
$tpl1->cc = 'support@megabytecircuit.com, {{customer_email}}';
$tpl1->bcc = 'audit@megabytecircuit.com';
$tpl1->save();

$rendered1 = EmailTemplateService::render('order_placed', null, [
    'customer_name' => 'John Doe',
    'customer_email' => 'john.doe@example.com',
    'order_number' => 'ORD-99999',
]);

echo "Rendered Success: " . ($rendered1['success'] ? 'YES' : 'NO') . "\n";
echo "TO (Primary): " . $rendered1['to'] . "\n";
echo "TO (All Parsed): " . implode(', ', $rendered1['to_all']) . "\n";
echo "FROM Name: " . $rendered1['from_name'] . "\n";
echo "FROM Email: " . $rendered1['from_email'] . "\n";
echo "CC List: " . implode(', ', $rendered1['cc']) . "\n";
echo "BCC List: " . implode(', ', $rendered1['bcc']) . "\n";

$pass1 = ($rendered1['to'] === 'john.doe@example.com') && 
         (in_array('orders@megabytecircuit.com', $rendered1['to_all'])) && 
         ($rendered1['from_email'] === 'notifications@megabytecircuit.com') &&
         (in_array('john.doe@example.com', $rendered1['cc']));

echo $pass1 ? "SUCCESS: All dynamic email fields resolved correctly!\n" : "FAIL: Dynamic resolution mismatch!\n";

// TEST CASE 2: Unresolved / Missing Variable Safeguard
echo "\n--- TEST CASE 2: Unresolved Recipient Variable Protection ---\n";
$tpl1->to = '{{customer_email}}';
$tpl1->save();

$rendered2 = EmailTemplateService::render('order_placed', null, [
    'customer_name' => 'Anonymous User',
    'customer_email' => '', // Empty email context
]);

echo "Rendered Success: " . ($rendered2['success'] ? 'YES' : 'NO') . "\n";
echo "Message: " . ($rendered2['message'] ?? 'N/A') . "\n";

$pass2 = (!$rendered2['success']) && str_contains($rendered2['message'], 'missing or unresolved');
echo $pass2 ? "SUCCESS: Missing recipient variable blocked email dispatch cleanly!\n" : "FAIL: Unresolved variable did not block dispatch!\n";

// TEST CASE 3: Inventory Template Dynamic Resolution
echo "\n--- TEST CASE 3: Inventory Low Stock Dynamic Resolution ---\n";
$tpl3 = EmailTemplate::where('key', 'inventory_low_stock')->first();
$tpl3->to = '{{company_email}}, warehouse@megabytecircuit.com';
$tpl3->from_name = '{{company_name}} Inventory Alert System';
$tpl3->save();

$rendered3 = EmailTemplateService::renderInventory('inventory_low_stock', null, null, [
    'product_name' => 'STM32 Microcontroller',
    'sku' => 'MCU-STM32F4',
    'current_stock' => '2',
    'minimum_stock' => '10',
]);

echo "Rendered Success: " . ($rendered3['success'] ? 'YES' : 'NO') . "\n";
echo "TO (Primary): " . $rendered3['to'] . "\n";
echo "FROM Name: " . $rendered3['from_name'] . "\n";
echo "Subject: " . $rendered3['subject'] . "\n";

$pass3 = ($rendered3['success']) && 
         str_contains($rendered3['from_name'], 'Inventory Alert System') && 
         str_contains($rendered3['subject'], 'STM32 Microcontroller');

echo $pass3 ? "SUCCESS: Inventory dynamic template resolved correctly!\n" : "FAIL: Inventory resolution error!\n";

echo "\n=== DYNAMIC EMAIL CONFIGURATION TEST SUITE COMPLETED ===\n";
