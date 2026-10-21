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

    public function test_j_series_parameter_generates_j_prefix_order_number(): void
    {
        $j1 = OrderNumberService::generateOrderNumber('J');
        $this->assertStringStartsWith('J', $j1);
        $this->assertFalse(str_starts_with($j1, 'JL'));
        $this->assertEquals('J0001', $j1);
    }

    public function test_generates_m_jl_and_j_series_independently(): void
    {
        $m1 = OrderNumberService::generateOrderNumber('M');
        $jl1 = OrderNumberService::generateOrderNumber('JL');
        $j1 = OrderNumberService::generateOrderNumber('J');
        $m2 = OrderNumberService::generateOrderNumber('M');
        $jl2 = OrderNumberService::generateOrderNumber('JL');
        $j2 = OrderNumberService::generateOrderNumber('J');

        $this->assertStringStartsWith('M', $m1);
        $this->assertStringStartsWith('JL', $jl1);
        $this->assertStringStartsWith('J', $j1);
        $this->assertFalse(str_starts_with($j1, 'JL'));

        $this->assertEquals('M0001', $m1);
        $this->assertEquals('JL0001', $jl1);
        $this->assertEquals('J0001', $j1);
        $this->assertEquals('M0002', $m2);
        $this->assertEquals('JL0002', $jl2);
        $this->assertEquals('J0002', $j2);
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

        $seqJBefore = DB::table('order_sequences')->where('series', 'J')->value('last_number') ?? 0;
        $numJ = OrderNumberService::generateOrderNumber('J');
        $seqJAfter = DB::table('order_sequences')->where('series', 'J')->value('last_number');

        $this->assertEquals($seqJBefore + 1, $seqJAfter);
        $this->assertEquals('J' . str_pad($seqJAfter, 4, '0', STR_PAD_LEFT), $numJ);
    }

    public function test_self_healing_reconciles_stale_j_sequence_counter_from_j0539_to_j0540(): void
    {
        // 1. Pre-existing sequence counter stuck at 20
        DB::table('order_sequences')->insert([
            'series' => 'J',
            'last_number' => 20,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // 2. Historical J orders up to J0539 and J0021 in pcb_orders
        DB::table('pcb_orders')->insert([
            'order_number' => 'J0021',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        DB::table('pcb_orders')->insert([
            'order_number' => 'J0539',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        // Also insert JL order to ensure it is excluded from J maximum
        DB::table('pcb_orders')->insert([
            'order_number' => 'JL0999',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Next number must reconcile from J0539 -> J0540
        $nextJ = OrderNumberService::generateOrderNumber('J');
        $this->assertEquals('J0540', $nextJ);

        // Verify J0021 order in DB remains completely unchanged
        $j0021Exists = DB::table('pcb_orders')->where('order_number', 'J0021')->exists();
        $this->assertTrue($j0021Exists);

        // Verify updated last_number in order_sequences is 540
        $seqAfter = DB::table('order_sequences')->where('series', 'J')->value('last_number');
        $this->assertEquals(540, $seqAfter);
    }

    public function test_sequence_counter_greater_than_historical_maximum_does_not_regress(): void
    {
        // Sequence counter set to 600 (higher than existing orders)
        DB::table('order_sequences')->insert([
            'series' => 'J',
            'last_number' => 600,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        DB::table('pcb_orders')->insert([
            'order_number' => 'J0539',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $nextJ = OrderNumberService::generateOrderNumber('J');
        $this->assertEquals('J0601', $nextJ);
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
