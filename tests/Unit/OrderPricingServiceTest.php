<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\OrderPricingService;

class OrderPricingServiceTest extends TestCase
{
    public function test_pcb_rate_calculation()
    {
        $params = [
            'pricing_method' => 'pcb_rate',
            'pcb_rate' => 100,
            'quantity' => 1000,
            'gst_rate' => 18,
        ];

        $pricing = OrderPricingService::calculateOrderPricing($params);

        $this->assertEquals('pcb_rate', $pricing['pricing_method']);
        $this->assertEquals(100, $pricing['pcb_rate']);
        $this->assertEquals(100000.00, $pricing['subtotal']);
        $this->assertEquals(18, $pricing['gst_rate']);
        $this->assertEquals(18000.00, $pricing['gst_amount']);
        $this->assertEquals(118000.00, $pricing['total_amount']);
        $this->assertEquals(100.00, $pricing['unit_price']);
    }

    public function test_price_per_sqm_calculation()
    {
        $params = [
            'pricing_method' => 'price_per_sqm',
            'price_per_sqm' => 5000,
            'dimensions_length' => 100,
            'dimensions_width' => 100,
            'quantity' => 1000,
            'gst_rate' => 18,
        ];

        $pricing = OrderPricingService::calculateOrderPricing($params);

        $this->assertEquals('price_per_sqm', $pricing['pricing_method']);
        $this->assertEquals(0.01, $pricing['area_per_board_sqm']);
        $this->assertEquals(10.0, $pricing['total_area_sqm']);
        $this->assertEquals(50000.00, $pricing['subtotal']);
        $this->assertEquals(9000.00, $pricing['gst_amount']);
        $this->assertEquals(59000.00, $pricing['total_amount']);
        $this->assertEquals(50.00, $pricing['unit_price']);
    }

    public function test_manual_price_override()
    {
        $params = [
            'pricing_method' => 'auto',
            'auto_calculated_value' => 8190.06,
            'manual_price' => 7500,
            'quantity' => 10,
            'gst_rate' => 18,
        ];

        $pricing = OrderPricingService::calculateOrderPricing($params);

        $this->assertEquals('manual', $pricing['pricing_method']);
        $this->assertEquals(7500.00, $pricing['manual_price']);
        $this->assertEquals(7500.00, $pricing['subtotal']);
        $this->assertEquals(18, $pricing['gst_rate']);
        $this->assertEquals(1350.00, $pricing['gst_amount']);
        $this->assertEquals(8850.00, $pricing['total_amount']);
        $this->assertEquals(750.00, $pricing['unit_price']);
    }

    public function test_dimension_unit_inch_conversion()
    {
        // 4 x 4 inches = 101.6 x 101.6 mm = 0.01032256 sqm per board
        $params = [
            'pricing_method' => 'price_per_sqm',
            'price_per_sqm' => 1000,
            'dimensions_length' => 4,
            'dimensions_width' => 4,
            'dimension_unit' => 'inches',
            'quantity' => 100,
            'gst_rate' => 18,
        ];

        $pricing = OrderPricingService::calculateOrderPricing($params);

        $this->assertEquals(round(0.010323, 4), round($pricing['area_per_board_sqm'], 4));
        $this->assertEquals(round(1.0323, 2), round($pricing['total_area_sqm'], 2));
    }
}
