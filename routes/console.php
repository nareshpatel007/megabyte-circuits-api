<?php

use Illuminate\Support\Facades\Artisan;
use App\Services\DeliveryCalendarService;

Artisan::command('holidays:cleanup', function () {
    DeliveryCalendarService::cleanupPastHolidays();
    $this->info('Past holidays cleaned up successfully.');
})->purpose('Auto-delete past holidays from database');



Artisan::command('order-db:verify', function () {
    $dbName = \Illuminate\Support\Facades\DB::getDatabaseName();

    foreach (['pcb_orders', 'pcb_order_combos', 'pcb_order_old_orders', 'pcb_order_meta', 'pcb_order_status_histories'] as $tbl) {
        $this->info("=== {$tbl} COLUMNS ===");
        foreach (\Illuminate\Support\Facades\DB::select("DESCRIBE {$tbl}") as $col) {
            $this->line(" - {$col->Field} | {$col->Type} | Null: {$col->Null} | Default: " . var_export($col->Default, true));
        }
    }

    // 1. Status verification
    $orphanStatus = \Illuminate\Support\Facades\DB::select("
        SELECT COUNT(*) as cnt
        FROM pcb_orders o
        LEFT JOIN pcb_order_statuses s ON s.id = o.status_id
        WHERE o.status_id IS NOT NULL AND s.id IS NULL
    ")[0]->cnt;

    $nullStatusId = \Illuminate\Support\Facades\DB::table('pcb_orders')->whereNull('status_id')->count();

    $this->info("=== 1. STATUS VERIFICATION ===");
    $this->line("Orphan status_id: {$orphanStatus} (Expected: 0)");
    $this->line("NULL status_id: {$nullStatusId} (Expected: 0)");

    // Check specific orders mentioned in audit
    $orderCols = ['order_number', 'status_id'];
    if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'status')) {
        $orderCols[] = 'status';
    }
    $specificOrders = \Illuminate\Support\Facades\DB::table('pcb_orders')
        ->whereIn('order_number', ['M00007', 'M00008', 'M4684-2'])
        ->get($orderCols);
    foreach ($specificOrders as $so) {
        $statusStr = isset($so->status) ? ", status={$so->status}" : "";
        $this->line(" - {$so->order_number}: status_id={$so->status_id}{$statusStr}");
    }

    // 2. Quantity verification
    $zeroQty = \Illuminate\Support\Facades\DB::table('pcb_orders')->where(function($q) {
        $q->whereNull('order_qty')->orWhere('order_qty', '<=', 0);
    })->count();

    if (\Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'completed_qty')) {
        $finalCompDiff = \Illuminate\Support\Facades\DB::table('pcb_orders')
            ->whereRaw('COALESCE(final_qty, 0) != COALESCE(completed_qty, 0)')
            ->count();
    } else {
        $finalCompDiff = 0;
    }

    // Inspect orders without pn_number
    $ordersWithoutPn = \Illuminate\Support\Facades\DB::table('pcb_orders')
        ->where(function($q) {
            $q->whereNull('pn_number')->orWhere('pn_number', '');
        })
        ->get(['id', 'order_number']);
    $this->info("=== ORDERS WITHOUT CANONICAL PN_NUMBER (" . count($ordersWithoutPn) . ") ===");
    foreach ($ordersWithoutPn as $opn) {
        $metaPn = \Illuminate\Support\Facades\DB::table('pcb_order_meta')
            ->where('pcb_order_id', $opn->id)
            ->whereIn('meta_key', ['p_n', 'pn_number', 'part_number', 'board_name'])
            ->pluck('meta_value', 'meta_key')
            ->toArray();
        $this->line(" - Order {$opn->order_number} (ID {$opn->id}): metas=" . json_encode($metaPn));
    }

    // 4. PN Number verification
    $ordersWithPn = \Illuminate\Support\Facades\DB::table('pcb_orders')->whereNotNull('pn_number')->where('pn_number', '!=', '')->count();
    $this->info("=== 4. PN NUMBER VERIFICATION ===");
    $this->line("Orders with canonical pn_number: {$ordersWithPn} / " . \Illuminate\Support\Facades\DB::table('pcb_orders')->count());

    // Check if any order without pn_number has p_n or part_number in meta
    $missingPnWithMeta = \Illuminate\Support\Facades\DB::select("
        SELECT o.id, o.order_number, m.meta_key, m.meta_value
        FROM pcb_orders o
        JOIN pcb_order_meta m ON m.pcb_order_id = o.id
        WHERE (o.pn_number IS NULL OR o.pn_number = '')
        AND m.meta_key IN ('p_n', 'part_number')
        AND m.meta_value IS NOT NULL AND TRIM(m.meta_value) != ''
    ");
    $this->line("Orders missing pn_number but having meta p_n / part_number: " . count($missingPnWithMeta));
    foreach ($missingPnWithMeta as $row) {
        $this->line(" - Order {$row->order_number}: meta key '{$row->meta_key}' = '{$row->meta_value}'");
    }

    // 5. Combo verification
    $comboCount = \Illuminate\Support\Facades\DB::table('pcb_order_combos')->count();
    $ordersWithComboText = \Illuminate\Support\Facades\DB::table('pcb_orders')->whereNotNull('combo')->where('combo', '!=', '')->count();
    $this->info("=== 5. COMBO VERIFICATION ===");
    $this->line("pcb_order_combos rows: {$comboCount}");
    $this->line("Orders with combo text: {$ordersWithComboText}");

    // 6. Foreign Keys verification
    $fks = \Illuminate\Support\Facades\DB::table('information_schema.KEY_COLUMN_USAGE')
        ->where('TABLE_SCHEMA', $dbName)
        ->where('TABLE_NAME', 'pcb_orders')
        ->whereNotNull('REFERENCED_TABLE_NAME')
        ->get(['CONSTRAINT_NAME', 'COLUMN_NAME', 'REFERENCED_TABLE_NAME', 'REFERENCED_COLUMN_NAME']);

    $this->info("=== 6. FOREIGN KEYS ON pcb_orders ===");
    foreach ($fks as $fk) {
        $this->line(" - {$fk->CONSTRAINT_NAME}: {$fk->COLUMN_NAME} -> {$fk->REFERENCED_TABLE_NAME}.{$fk->REFERENCED_COLUMN_NAME}");
    }

    // 7. Indexes verification
    $indexes = collect(\Illuminate\Support\Facades\DB::select("SHOW INDEXES FROM pcb_orders"))
        ->pluck('Key_name')
        ->unique();
    $this->info("=== 7. INDEXES ON pcb_orders ===");
    foreach ($indexes as $idx) {
        $this->line(" - {$idx}");
    }
})->purpose('Run comprehensive verification of PCB orders database');

Artisan::command('order-db:test', function () {
    $this->info("============================================================");
    $this->info("RUNNING PCB ORDERS NORMALIZATION VERIFICATION TEST SUITE");
    $this->info("============================================================");

    $passed = 0;
    $failed = 0;

    $assert = function ($condition, $name) use (&$passed, &$failed) {
        if ($condition) {
            $this->info(" [PASS] {$name}");
            $passed++;
        } else {
            $this->error(" [FAIL] {$name}");
            $failed++;
        }
    };

    // 1. FOREIGN KEY INTEGRITY & ORPHAN CHECKS
    $this->line("\n--- 1. Foreign Key Integrity & Orphan Detection ---");
    $dbName = \Illuminate\Support\Facades\DB::getDatabaseName();
    $fks = \Illuminate\Support\Facades\DB::table('information_schema.KEY_COLUMN_USAGE')
        ->where('TABLE_SCHEMA', $dbName)
        ->where('TABLE_NAME', 'pcb_orders')
        ->whereNotNull('REFERENCED_TABLE_NAME')
        ->pluck('CONSTRAINT_NAME')
        ->toArray();

    $expectedFks = [
        'fk_pcb_orders_status_id',
        'fk_pcb_orders_user_id',
        'fk_pcb_orders_transaction_id',
        'fk_pcb_orders_shipping_address_id',
        'fk_pcb_orders_billing_address_id',
        'fk_pcb_orders_gerber_file_id',
        'fk_pcb_orders_source_order_id',
    ];

    foreach ($expectedFks as $efk) {
        $assert(in_array($efk, $fks), "Foreign Key Constraint exists: {$efk}");
    }

    // Check 0 orphans for all 7 FK relationships
    $orphanStatus = \Illuminate\Support\Facades\DB::select("
        SELECT COUNT(*) as cnt FROM pcb_orders o
        LEFT JOIN pcb_order_statuses s ON s.id = o.status_id
        WHERE o.status_id IS NOT NULL AND s.id IS NULL
    ")[0]->cnt;
    $assert($orphanStatus == 0, "Zero orphan status_id references (Count: {$orphanStatus})");

    $orphanUser = \Illuminate\Support\Facades\DB::select("
        SELECT COUNT(*) as cnt FROM pcb_orders o
        LEFT JOIN users u ON u.id = o.user_id
        WHERE o.user_id IS NOT NULL AND u.id IS NULL
    ")[0]->cnt;
    $assert($orphanUser == 0, "Zero orphan user_id references (Count: {$orphanUser})");

    $orphanTxn = \Illuminate\Support\Facades\DB::select("
        SELECT COUNT(*) as cnt FROM pcb_orders o
        LEFT JOIN payment_transactions pt ON pt.id = o.transaction_id
        WHERE o.transaction_id IS NOT NULL AND pt.id IS NULL
    ")[0]->cnt;
    $assert($orphanTxn == 0, "Zero orphan transaction_id references (Count: {$orphanTxn})");

    $orphanShip = \Illuminate\Support\Facades\DB::select("
        SELECT COUNT(*) as cnt FROM pcb_orders o
        LEFT JOIN user_addresses a ON a.id = o.shipping_address_id
        WHERE o.shipping_address_id IS NOT NULL AND a.id IS NULL
    ")[0]->cnt;
    $assert($orphanShip == 0, "Zero orphan shipping_address_id references (Count: {$orphanShip})");

    $orphanBill = \Illuminate\Support\Facades\DB::select("
        SELECT COUNT(*) as cnt FROM pcb_orders o
        LEFT JOIN user_addresses a ON a.id = o.billing_address_id
        WHERE o.billing_address_id IS NOT NULL AND a.id IS NULL
    ")[0]->cnt;
    $assert($orphanBill == 0, "Zero orphan billing_address_id references (Count: {$orphanBill})");

    $orphanGerber = \Illuminate\Support\Facades\DB::select("
        SELECT COUNT(*) as cnt FROM pcb_orders o
        LEFT JOIN gerber_files g ON g.id = o.gerber_file_id
        WHERE o.gerber_file_id IS NOT NULL AND g.id IS NULL
    ")[0]->cnt;
    $assert($orphanGerber == 0, "Zero orphan gerber_file_id references (Count: {$orphanGerber})");

    $orphanSource = \Illuminate\Support\Facades\DB::select("
        SELECT COUNT(*) as cnt FROM pcb_orders o
        LEFT JOIN pcb_orders p ON p.id = o.source_order_id
        WHERE o.source_order_id IS NOT NULL AND p.id IS NULL
    ")[0]->cnt;
    $assert($orphanSource == 0, "Zero orphan source_order_id references (Count: {$orphanSource})");

    // 2. DATA INTEGRITY & CANONICAL CONSISTENCY
    $this->line("\n--- 2. Data Integrity & Canonical Consistency ---");
    $duplicateOrders = \Illuminate\Support\Facades\DB::select("
        SELECT order_number, COUNT(*) as cnt FROM pcb_orders GROUP BY order_number HAVING COUNT(*) > 1
    ");
    $assert(count($duplicateOrders) == 0, "Zero duplicate order numbers in pcb_orders");

    // Verify dropped physical columns
    $assert(!\Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'status'), "Physical column pcb_orders.status is completely dropped (Expected: true)");
    $assert(!\Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'customer_name'), "Physical column pcb_orders.customer_name is completely dropped (Expected: true)");
    $assert(!\Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'completed_qty'), "Physical column pcb_orders.completed_qty is completely dropped (Expected: true)");
    $assert(!\Illuminate\Support\Facades\Schema::hasColumn('pcb_orders', 'board_name'), "Physical column pcb_orders.board_name is completely dropped (Expected: true)");
    $assert(\Illuminate\Support\Facades\Schema::hasColumn('pcb_order_status_histories', 'status_id'), "pcb_order_status_histories has canonical status_id column (Expected: true)");

    $ordersWithStatus = \Illuminate\Support\Facades\DB::table('pcb_orders')->whereNull('status_id')->count();
    $assert($ordersWithStatus == 0, "All orders have a valid status_id (NULL count: {$ordersWithStatus})");

    // 3. MODEL ACCESSORS & RELATIONSHIPS
    $this->line("\n--- 3. Model Accessors & Relationships ---");
    $sampleOrder = \App\Models\PcbOrder::with(['statusDetails', 'user', 'client', 'meta'])->first();
    $assert($sampleOrder !== null, "Sample order loaded successfully");
    if ($sampleOrder) {
        $statusName = $sampleOrder->status;
        $expectedStatusName = $sampleOrder->statusDetails ? $sampleOrder->statusDetails->name : 'Pending';
        $assert($statusName === $expectedStatusName, "Status accessor returns canonical statusDetails->name ('{$statusName}')");

        $custName = $sampleOrder->customer_name;
        $assert(!empty($custName), "Customer name accessor resolves successfully ('{$custName}')");

        $assert(is_int($sampleOrder->completed_qty), "Completed qty accessor returns integer ({$sampleOrder->completed_qty})");
        $assert($sampleOrder->completed_qty === (int)$sampleOrder->final_qty, "Completed qty accessor aliases final_qty");

        $assert(method_exists($sampleOrder, 'user'), "PcbOrder has user() relation");
        $assert(method_exists($sampleOrder, 'client'), "PcbOrder has client() relation (canonical alias)");
        $assert($sampleOrder->client_id === $sampleOrder->user_id, "PcbOrder client_id attribute maps to user_id ({$sampleOrder->client_id})");
        $assert(method_exists($sampleOrder, 'statusDetails'), "PcbOrder has statusDetails() relation");
        $assert(method_exists($sampleOrder, 'transaction'), "PcbOrder has transaction() relation");
        $assert(method_exists($sampleOrder, 'shippingAddress'), "PcbOrder has shippingAddress() relation");
        $assert(method_exists($sampleOrder, 'billingAddress'), "PcbOrder has billingAddress() relation");
        $assert(method_exists($sampleOrder, 'gerberFile'), "PcbOrder has gerberFile() relation");
        $assert(method_exists($sampleOrder, 'sourceOrder'), "PcbOrder has sourceOrder() relation");
        $assert(method_exists($sampleOrder, 'reorderChildren'), "PcbOrder has reorderChildren() relation");
        $assert(method_exists($sampleOrder, 'comboOrders'), "PcbOrder has comboOrders() relation");
        $assert(method_exists($sampleOrder, 'oldOrders'), "PcbOrder has oldOrders() relation");
        $assert(method_exists($sampleOrder, 'meta'), "PcbOrder has meta() relation");
    }

    // 4. ORDER LIFECYCLE WORKFLOWS
    $this->line("\n--- 4. Order Lifecycle Workflows (Create, Edit, Reorder, Combo, Delete) ---");
    $testOrderNum = 'TEST_NORM_' . time();
    $pendingId = \App\Services\OrderStatusResolver::getDefaultStatus()->id;

    // A. Creation
    $createdOrder = new \App\Models\PcbOrder();
    $createdOrder->order_number = $testOrderNum;
    $createdOrder->order_type = 'normal';
    $createdOrder->quotation_source = 'internal';
    $createdOrder->status_id = $pendingId;
    $createdOrder->status = 'Pending';
    $createdOrder->order_qty = 10;
    $createdOrder->layers = 2;
    $createdOrder->mask = 'Green';
    $createdOrder->unit_price = 100.00;
    $createdOrder->order_value = 1000.00;
    $createdOrder->save();

    $assert($createdOrder->id > 0, "Test order created directly with canonical fields (ID: {$createdOrder->id})");

    // Verify redundant keys are NOT in pcb_order_meta
    $redundantCount = \Illuminate\Support\Facades\DB::table('pcb_order_meta')
        ->where('pcb_order_id', $createdOrder->id)
        ->whereIn('meta_key', ['order_number', 'status', 'unit_price', 'order_value', 'gerber_file_id'])
        ->count();
    $assert($redundantCount == 0, "No duplicate redundant keys created in pcb_order_meta (Count: {$redundantCount})");

    // B. Edit via OrderDataService
    \App\Services\OrderDataService::updateCanonicalOrderFields($createdOrder, [
        'pn_number' => 'PN-TEST-999',
        'order_qty' => 25,
        'final_qty' => 20,
        'layers' => 4,
        'mask' => 'Black',
    ]);
    $createdOrder->save();
    $createdOrder->refresh();

    $assert($createdOrder->pn_number === 'PN-TEST-999', "Canonical pn_number updated: {$createdOrder->pn_number}");
    $assert($createdOrder->order_qty === 25, "Canonical order_qty updated: {$createdOrder->order_qty}");
    $assert($createdOrder->final_qty === 20, "Canonical final_qty updated: {$createdOrder->final_qty}");
    $assert($createdOrder->completed_qty === 20, "Completed_qty automatically kept in sync with final_qty: {$createdOrder->completed_qty}");
    $assert((int)$createdOrder->layers === 4, "Canonical layers updated: {$createdOrder->layers}");
    $assert($createdOrder->mask === 'Black', "Canonical mask updated: {$createdOrder->mask}");

    // C. Reorder Child Creation
    $reorderNum = $testOrderNum . '-1';
    $reorderChild = new \App\Models\PcbOrder();
    $reorderChild->order_number = $reorderNum;
    $reorderChild->order_type = 'normal';
    $reorderChild->quotation_source = 'internal';
    $reorderChild->status_id = $pendingId;
    $reorderChild->status = 'Pending';
    $reorderChild->order_qty = 25;
    $reorderChild->layers = 4;
    $reorderChild->mask = 'Black';
    $reorderChild->unit_price = 100.00;
    $reorderChild->order_value = 2500.00;
    $reorderChild->source_order_id = $createdOrder->id;
    $reorderChild->source_order_number = $createdOrder->order_number;
    $reorderChild->is_reorder = true;
    $reorderChild->save();

    $assert($reorderChild->sourceOrder !== null && $reorderChild->sourceOrder->id === $createdOrder->id, "Reorder sourceOrder relationship resolves to parent order");
    $assert($createdOrder->reorderChildren()->count() === 1, "Parent reorderChildren() contains child order");

    // D. Soft Delete & Restore
    $reorderChild->delete();
    $assert(\App\Models\PcbOrder::find($reorderChild->id) === null, "Order soft deleted successfully");
    $assert(\App\Models\PcbOrder::withTrashed()->find($reorderChild->id) !== null, "Soft deleted order accessible via withTrashed()");
    $reorderChild->restore();
    $assert(\App\Models\PcbOrder::find($reorderChild->id) !== null, "Order restored successfully");

    // Clean up test records
    $reorderChild->forceDelete();
    $createdOrder->forceDelete();
    $this->line(" Test records cleaned up successfully.");

    // E. Invalid status_id rejection test (Section 8)
    $this->line("\n--- E. Status Validation & Invalid ID Rejection ---");
    $invalidOrder = new \App\Models\PcbOrder();
    $invalidOrder->order_number = 'TEST_INV_' . time();
    $invalidOrder->status_id = 999999;
    $rejected = false;
    try {
        $invalidOrder->save();
    } catch (\Illuminate\Validation\ValidationException $ve) {
        $rejected = true;
    }
    $assert($rejected, "Invalid status_id=999999 is strictly rejected with ValidationException");

    // F. Status Transitions: Pending -> Traveler -> Drilling -> Completed (Section 10 & 34)
    $this->line("\n--- F. Canonical Status Transitions (Pending -> Traveler -> Drilling -> Completed) ---");
    $transOrder = new \App\Models\PcbOrder();
    $transOrder->order_number = 'TEST_TRANS_' . time();
    $transOrder->order_type = 'normal';
    $transOrder->order_qty = 10;
    $transOrder->save(); // Defaults to Pending

    $assert($transOrder->status_id === $pendingId, "New order starts with Pending status_id: {$pendingId}");
    $assert(strtolower($transOrder->status) === 'pending', "Derived status is 'Pending'");

    $travelerStatus = \App\Services\OrderStatusResolver::resolve('Traveler');
    if ($travelerStatus) {
        $transOrder->status_id = $travelerStatus->id;
        $transOrder->save();
        $transOrder->refresh();
        $assert($transOrder->status_id === $travelerStatus->id, "Transitioned to Traveler (ID: {$travelerStatus->id})");
        $assert($transOrder->status === $travelerStatus->name, "Derived status resolves to '{$travelerStatus->name}'");
    }

    $drillingStatus = \App\Services\OrderStatusResolver::resolve('Drilling');
    if ($drillingStatus) {
        $transOrder->status_id = $drillingStatus->id;
        $transOrder->save();
        $transOrder->refresh();
        $assert($transOrder->status_id === $drillingStatus->id, "Transitioned to Drilling (ID: {$drillingStatus->id})");
        $assert($transOrder->status === $drillingStatus->name, "Derived status resolves to '{$drillingStatus->name}'");
    }

    $completedStatus = \App\Services\OrderStatusResolver::resolve('Completed');
    if ($completedStatus) {
        $transOrder->status_id = $completedStatus->id;
        $transOrder->bill_number = 'BILL-TEST-123';
        $transOrder->save();
        $transOrder->refresh();
        $assert($transOrder->status_id === $completedStatus->id, "Transitioned to Completed (ID: {$completedStatus->id})");
        $assert($transOrder->status === $completedStatus->name, "Derived status resolves to '{$completedStatus->name}'");
    }

    // G. Combo Status Propagation Test (Section 26 & 34)
    $this->line("\n--- G. Combo Status Propagation using canonical status_id ---");
    $childComboOrder = new \App\Models\PcbOrder();
    $childComboOrder->order_number = 'TEST_CMB_C_' . time();
    $childComboOrder->order_type = 'normal';
    $childComboOrder->status_id = $pendingId;
    $childComboOrder->save();

    \App\Services\ComboOrderService::syncComboOrders($transOrder, [$childComboOrder->order_number], $err);
    if ($travelerStatus) {
        \App\Services\ComboOrderService::syncComboStatus($transOrder, $travelerStatus->id, 1, 'Admin');
        $childComboOrder->refresh();
        $assert($childComboOrder->status_id === $travelerStatus->id, "Combo child order received canonical status_id ({$travelerStatus->id}) from parent");
    }

    // Cleanup transition & combo test orders
    $childComboOrder->forceDelete();
    $transOrder->forceDelete();

    // 5. MANUFACTURING PROVIDER ROUTING REGRESSION (PHASE 25)
    $this->line("\n--- 5. Critical PCB Provider Routing Regression ---");

    $inHouse2Layer = \App\Services\OrderPricingService::resolveOrderSource(['layers' => 2, 'base_material' => 'FR-4']);
    $assert($inHouse2Layer['quotation_source'] === 'internal' && $inHouse2Layer['series'] === 'M', "2-layer FR-4 routes to IN-HOUSE (M series)");

    $jlcpcb4Layer = \App\Services\OrderPricingService::resolveOrderSource(['layers' => 4, 'base_material' => 'FR-4']);
    $assert($jlcpcb4Layer['quotation_source'] === 'jlcpcb' && $jlcpcb4Layer['series'] === 'JL', "4-layer FR-4 routes to JLCPCB (JL series)");

    $jlcpcbEnig = \App\Services\OrderPricingService::resolveOrderSource(['layers' => 2, 'base_material' => 'FR-4', 'surface_finish' => 'ENIG']);
    $assert($jlcpcbEnig['quotation_source'] === 'jlcpcb' && $jlcpcbEnig['series'] === 'JL', "2-layer + ENIG routes to JLCPCB (JL series)");

    $jlcpcbThin = \App\Services\OrderPricingService::resolveOrderSource(['layers' => 2, 'base_material' => 'FR-4', 'thickness' => '0.6mm']);
    $assert($jlcpcbThin['quotation_source'] === 'jlcpcb' && $jlcpcbThin['series'] === 'JL', "2-layer + 0.6mm thickness routes to JLCPCB (JL series)");

    $jlcpcbViaSpecial = \App\Services\OrderPricingService::resolveOrderSource(['layers' => 2, 'base_material' => 'FR-4', 'via_covering' => 'Epoxy Filled & Capped']);
    $assert($jlcpcbViaSpecial['quotation_source'] === 'jlcpcb' && $jlcpcbViaSpecial['series'] === 'JL', "2-layer + special via (Epoxy Filled & Capped) routes to JLCPCB (JL series)");

    $this->info("\n============================================================");
    $this->info("RESULTS: {$passed} PASSED, {$failed} FAILED");
    $this->info("============================================================");

    return $failed === 0 ? 0 : 1;
})->purpose('Run PCB orders normalization verification test suite');
