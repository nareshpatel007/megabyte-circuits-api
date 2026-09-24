<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\JlcpcbService;
use App\Models\PcbPricingSetting;
use Illuminate\Support\Facades\Schema;

class JlcpcbMarginPricingTest extends TestCase
{
    public function test_margin_and_pricing_calculation_formula()
    {
        // 1. Given parameters from requirement 7 test case:
        $providerBoardPriceInr = 23780.00;
        $shippingInr = 0.00;
        $marginPct = 20.0;
        $gstPct = 18.0;

        // 2. Step-by-step formula execution:
        // Step 1: Calculate margin
        $marginAmount = round($providerBoardPriceInr * ($marginPct / 100.0), 2);
        $this->assertEquals(4756.00, $marginAmount);

        // Step 2: Customer PCB Base Price
        $customerBasePrice = round($providerBoardPriceInr + $marginAmount, 2);
        $this->assertEquals(28536.00, $customerBasePrice);

        // Step 3: Subtotal Excluding GST
        $subtotalExclGst = round($customerBasePrice + $shippingInr, 2);
        $this->assertEquals(28536.00, $subtotalExclGst);

        // Step 4: GST Amount
        $gstAmount = round($subtotalExclGst * ($gstPct / 100.0), 2);
        $this->assertEquals(5136.48, $gstAmount);

        // Step 5: Final Total
        $finalTotal = round($subtotalExclGst + $gstAmount, 2);
        $this->assertEquals(33672.48, $finalTotal);
    }
}
