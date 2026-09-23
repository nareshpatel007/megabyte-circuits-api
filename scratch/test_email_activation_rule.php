<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\EmailTemplate;
use App\Services\EmailTemplateService;
use App\Services\DailyReportService;

echo "=== TESTING CENTRALIZED EMAIL ACTIVATION RULE ===\n\n";

// 1. Test template key 'order_status_updated'
$tpl = EmailTemplate::where('key', 'order_status_updated')->first();
if ($tpl) {
    echo "1. Testing 'order_status_updated' template:\n";
    
    // Set to inactive
    $tpl->is_active = 0;
    $tpl->save();
    
    echo " - Set is_active = 0\n";
    $isActive = EmailTemplateService::isTemplateActive('order_status_updated');
    echo " - EmailTemplateService::isTemplateActive('order_status_updated'): " . ($isActive ? 'TRUE' : 'FALSE') . "\n";
    
    $sendResult = EmailTemplateService::sendOrderEmail('order_status_updated', 1001, null, ['previous_order_status' => 'Pending']);
    echo " - sendOrderEmail result when inactive: " . ($sendResult ? 'DISPATCHED (FAIL)' : 'SKIPPED (SUCCESS)') . "\n";
    
    // Set to active
    $tpl->is_active = 1;
    $tpl->save();
    
    echo " - Restored is_active = 1\n";
    $isActive = EmailTemplateService::isTemplateActive('order_status_updated');
    echo " - EmailTemplateService::isTemplateActive('order_status_updated'): " . ($isActive ? 'TRUE' : 'FALSE') . "\n";
}

echo "\n2. Testing 'daily_order_progress_report' template:\n";
$reportTpl = EmailTemplate::where('key', 'daily_order_progress_report')->first();
if ($reportTpl) {
    $reportTpl->is_active = 0;
    $reportTpl->save();
    
    $resInactive = DailyReportService::sendDailyOrderReport(null, true);
    echo " - sendDailyOrderReport when inactive: " . json_encode($resInactive) . "\n";
    
    $reportTpl->is_active = 1;
    $reportTpl->save();
    
    echo " - Restored is_active = 1 for daily_order_progress_report\n";
}

echo "\n3. Testing 'inventory_low_stock' template:\n";
$invTpl = EmailTemplate::where('key', 'inventory_low_stock')->first();
if ($invTpl) {
    $invTpl->is_active = 0;
    $invTpl->save();
    
    $sendInvInactive = EmailTemplateService::sendInventoryEmail('inventory_low_stock', 1);
    echo " - sendInventoryEmail result when inactive: " . ($sendInvInactive ? 'DISPATCHED (FAIL)' : 'SKIPPED (SUCCESS)') . "\n";
    
    $invTpl->is_active = 1;
    $invTpl->save();
    echo " - Restored is_active = 1 for inventory_low_stock\n";
}

echo "\n=== EMAIL ACTIVATION RULE VERIFICATION COMPLETE ===\n";
