<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\OrderNumberService;
use Illuminate\Support\Facades\DB;

class OrderNumberServiceTest extends TestCase
{
    public function test_generates_m_and_j_series_independently(): void
    {
        $m1 = OrderNumberService::generateOrderNumber('M');
        $j1 = OrderNumberService::generateOrderNumber('J');
        $m2 = OrderNumberService::generateOrderNumber('M');
        $j2 = OrderNumberService::generateOrderNumber('J');

        $this->assertStringStartsWith('M', $m1);
        $this->assertStringStartsWith('J', $j1);
        $this->assertStringStartsWith('M', $m2);
        $this->assertStringStartsWith('J', $j2);

        $m1Num = (int) substr($m1, 1);
        $m2Num = (int) substr($m2, 1);
        $j1Num = (int) substr($j1, 1);
        $j2Num = (int) substr($j2, 1);

        $this->assertEquals($m1Num + 1, $m2Num);
        $this->assertEquals($j1Num + 1, $j2Num);
    }

    public function test_generates_correct_series_for_order_type(): void
    {
        $jlcNumber = OrderNumberService::generateForType('jlcpcb');
        $normalNumber = OrderNumberService::generateForType('normal');

        $this->assertStringStartsWith('J', $jlcNumber);
        $this->assertStringStartsWith('M', $normalNumber);
    }

    public function test_sequence_counter_increments_atomically(): void
    {
        $seqMBefore = DB::table('order_sequences')->where('series', 'M')->value('last_number') ?? 0;
        $numM = OrderNumberService::generateOrderNumber('M');
        $seqMAfter = DB::table('order_sequences')->where('series', 'M')->value('last_number');

        $this->assertEquals($seqMBefore + 1, $seqMAfter);
        $this->assertEquals('M' . str_pad($seqMAfter, 5, '0', STR_PAD_LEFT), $numM);

        $seqJBefore = DB::table('order_sequences')->where('series', 'J')->value('last_number') ?? 0;
        $numJ = OrderNumberService::generateOrderNumber('J');
        $seqJAfter = DB::table('order_sequences')->where('series', 'J')->value('last_number');

        $this->assertEquals($seqJBefore + 1, $seqJAfter);
        $this->assertEquals('J' . str_pad($seqJAfter, 5, '0', STR_PAD_LEFT), $numJ);
    }
}
