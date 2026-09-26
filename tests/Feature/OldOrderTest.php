<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\PcbOrder;
use App\Models\PcbOrderOldOrder;
use App\Models\PcbOrderCombo;
use App\Services\OldOrderService;
use App\Services\ComboOrderService;

class OldOrderTest extends TestCase
{
    public function test_old_order_service_sync_and_validation()
    {
        // 1. Create test orders with minimal columns
        $orderMain = new PcbOrder();
        $orderMain->order_number = 'TEST_M001';
        $orderMain->status = 'Pending';
        $orderMain->save();

        $old1 = new PcbOrder();
        $old1->order_number = 'TEST_M002';
        $old1->status = 'Completed';
        $old1->save();

        $old2 = new PcbOrder();
        $old2->order_number = 'TEST_M003';
        $old2->status = 'Completed';
        $old2->save();

        $combo1 = new PcbOrder();
        $combo1->order_number = 'TEST_M004';
        $combo1->status = 'Pending';
        $combo1->save();

        // Case 1: Select multiple Old Orders & self-selection / duplicate check
        $err = null;
        $success = OldOrderService::syncOldOrders($orderMain, [
            $old1->id,
            $old2->id,
            $orderMain->id, // Self selection (should be skipped)
            $old1->id,      // Duplicate (should be deduplicated)
        ], $err);

        $this->assertTrue($success);
        $this->assertNull($err);

        $orderMain->refresh();
        $this->assertEquals(2, $orderMain->oldOrders()->count());
        $this->assertStringContainsString('TEST_M002', $orderMain->old_order_number);
        $this->assertStringContainsString('TEST_M003', $orderMain->old_order_number);

        // Case 2: Combo Independence - set combo orders on orderMain
        $comboErr = null;
        ComboOrderService::syncComboOrders($orderMain, [$combo1->id], $comboErr);
        $orderMain->refresh();

        $this->assertEquals(1, $orderMain->comboOrders()->count());
        $this->assertEquals(2, $orderMain->oldOrders()->count());

        // Case 3: Update Old Orders (replace selection) - remove old2, keep old1
        OldOrderService::syncOldOrders($orderMain, [$old1->id], $err);
        $orderMain->refresh();

        $this->assertEquals(1, $orderMain->oldOrders()->count());
        $this->assertEquals('TEST_M002', $orderMain->old_order_number);
        // Ensure combo remains unchanged!
        $this->assertEquals(1, $orderMain->comboOrders()->count());

        // Case 4: Clear all Old Orders
        OldOrderService::syncOldOrders($orderMain, [], $err);
        $orderMain->refresh();

        $this->assertEquals(0, $orderMain->oldOrders()->count());
        $this->assertNull($orderMain->old_order_number);
        // Ensure combo still remains unchanged!
        $this->assertEquals(1, $orderMain->comboOrders()->count());

        // Cleanup
        PcbOrderCombo::where('parent_order_id', $orderMain->id)->delete();
        PcbOrderOldOrder::where('order_id', $orderMain->id)->delete();
        $orderMain->forceDelete();
        $old1->forceDelete();
        $old2->forceDelete();
        $combo1->forceDelete();
    }
}
