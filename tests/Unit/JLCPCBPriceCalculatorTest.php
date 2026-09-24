<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\JLCPCBPriceCalculator;

class JLCPCBPriceCalculatorTest extends TestCase
{
    public function test_requirement_28_exact_test_case()
    {
        $calculator = new JLCPCBPriceCalculator();

        $pcbPriceUsd = 75.00;

        $overrideSettings = [
            'international_shipping_usd' => 25.00,
            'usd_to_inr_rate' => 100.00,
            'customs_duty_percent' => 30.00,
            'customs_other_charges' => 0.00,
            'sws_charges' => 0.00,
            'import_gst_percent' => 18.00,
            'customs_clearing' => 0.00,
            'bank_forex_payment_charges' => 0.00,
            'domestic_freight' => 1000.00,
            'other_buy_expenses' => 0.00,
            'margin_markup_percent' => 20.00,
            'sales_gst_percent' => 18.00
        ];

        $res = $calculator->calculate($pcbPriceUsd, $overrideSettings, 1);

        $this->assertEquals(75.00, $res['pcb_purchase_price_usd']);
        $this->assertEquals(25.00, $res['international_shipping_usd']);
        $this->assertEquals(100.00, $res['total_usd']);
        $this->assertEquals(10000.00, $res['import_purchase_value']);
        $this->assertEquals(3000.00, $res['customs_duty']);
        $this->assertEquals(2340.00, $res['import_gst']);
        $this->assertEquals(1000.00, $res['domestic_freight']);
        $this->assertEquals(16340.00, $res['total_buy_cost']);
        $this->assertEquals(3268.00, $res['margin_amount']);
        $this->assertEquals(19608.00, $res['selling_price_before_gst']);
        $this->assertEquals(3529.44, $res['sales_gst_amount']);
        $this->assertEquals(23137.44, $res['final_customer_price']);
    }

    public function test_zero_shipping_fallback_uses_api_shipping()
    {
        $calculator = new JLCPCBPriceCalculator();

        $pcbPriceUsd = 8.00;
        $apiShippingUsd = 9.86;

        $overrideSettings = [
            'international_shipping_usd' => 0.00,
            'usd_to_inr_rate' => 100.00,
            'customs_duty_percent' => 30.00,
            'import_gst_percent' => 18.00,
            'domestic_freight' => 1000.00,
            'margin_markup_percent' => 25.00,
            'sales_gst_percent' => 18.00
        ];

        $res = $calculator->calculate($pcbPriceUsd, $overrideSettings, 5, $apiShippingUsd);

        $this->assertEquals(8.00, $res['pcb_purchase_price_usd']);
        $this->assertEquals(9.86, $res['international_shipping_usd']);
        $this->assertEquals(17.86, $res['total_usd']);
        $this->assertEquals(1786.00, $res['import_purchase_value']);
        $this->assertEquals('jlcpcb_api', $res['shipping_source']);
    }
}
