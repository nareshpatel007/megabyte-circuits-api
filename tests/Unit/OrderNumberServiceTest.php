<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\OrderNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderNumberServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('order_sequences');
        Schema::dropIfExists('pcb_orders');

        Schema::create('order_sequences', function ($table) {
            $table->id();
            $table->string('series', 10)->unique();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::create('pcb_orders', function ($table) {
            $table->id();
            $table->string('order_number', 100)->nullable();
            $table->string('order_type', 50)->nullable();
            $table->string('quotation_source', 50)->nullable();
            $table->timestamps();
        });
    }

    public function test_generates_jlcpcb_order_number_with_jl_prefix_and_four_digits(): void
    {
        $jl1 = OrderNumberService::generateOrderNumber('JL');
        $jl2 = OrderNumberService::generateOrderNumber('JL');
        $jl3 = OrderNumberService::generateOrderNumber('JL');

        $this->assertEquals('JL0001', $jl1);
        $this->assertEquals('JL0002', $jl2);
        $this->assertEquals('JL0003', $jl3);
    }

    public function test_legacy_j_series_parameter_maps_to_jl_format(): void
    {
        $j1 = OrderNumberService::generateOrderNumber('J');
        $this->assertStringStartsWith('JL', $j1);
        $this->assertEquals('JL0001', $j1);
    }

    public function test_generates_m_and_jl_series_independently(): void
    {
        $m1 = OrderNumberService::generateOrderNumber('M');
        $jl1 = OrderNumberService::generateOrderNumber('JL');
        $m2 = OrderNumberService::generateOrderNumber('M');
        $jl2 = OrderNumberService::generateOrderNumber('JL');

        $this->assertStringStartsWith('M', $m1);
        $this->assertStringStartsWith('JL', $jl1);
        $this->assertStringStartsWith('M', $m2);
        $this->assertStringStartsWith('JL', $jl2);

        $this->assertEquals('JL0001', $jl1);
        $this->assertEquals('JL0002', $jl2);
    }

    public function test_generates_correct_series_for_order_type(): void
    {
        $jlcNumber = OrderNumberService::generateForType('jlcpcb');
        $normalNumber = OrderNumberService::generateForType('normal');

        $this->assertStringStartsWith('JL', $jlcNumber);
        $this->assertStringStartsWith('M', $normalNumber);
    }

    public function test_sequence_counter_increments_atomically(): void
    {
        $seqMBefore = DB::table('order_sequences')->where('series', 'M')->value('last_number') ?? 0;
        $numM = OrderNumberService::generateOrderNumber('M');
        $seqMAfter = DB::table('order_sequences')->where('series', 'M')->value('last_number');

        $this->assertEquals($seqMBefore + 1, $seqMAfter);
        $this->assertEquals('M' . str_pad($seqMAfter, 4, '0', STR_PAD_LEFT), $numM);

        $seqJLBefore = DB::table('order_sequences')->where('series', 'JL')->value('last_number') ?? 0;
        $numJL = OrderNumberService::generateOrderNumber('JL');
        $seqJLAfter = DB::table('order_sequences')->where('series', 'JL')->value('last_number');

        $this->assertEquals($seqJLBefore + 1, $seqJLAfter);
        $this->assertEquals('JL' . str_pad($seqJLAfter, 4, '0', STR_PAD_LEFT), $numJL);
    }

    public function test_existing_j_orders_do_not_prevent_starting_jl_from_0001(): void
    {
        // Insert historical J-prefixed orders
        DB::table('pcb_orders')->insert([
            'order_number' => 'J0001',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        DB::table('pcb_orders')->insert([
            'order_number' => 'J0002',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $nextJL = OrderNumberService::generateOrderNumber('JL');
        $this->assertEquals('JL0001', $nextJL);
    }

    public function test_continues_from_highest_existing_jl_number(): void
    {
        // Insert existing JL orders
        DB::table('pcb_orders')->insert([
            'order_number' => 'JL0001',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        DB::table('pcb_orders')->insert([
            'order_number' => 'JL0008',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $nextJL = OrderNumberService::generateOrderNumber('JL');
        $this->assertEquals('JL0009', $nextJL);
    }
}
