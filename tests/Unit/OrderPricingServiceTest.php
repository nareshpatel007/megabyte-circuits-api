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

    public function test_1_and_2_layer_fr4_use_local_pricing_and_m_series(): void
    {
        // 1 layer FR-4
        $res1 = OrderPricingService::resolveOrderSource(['layers' => 1, 'base_material' => 'FR-4']);
        $this->assertFalse(OrderPricingService::isJlcpcbRequired(['layers' => 1, 'base_material' => 'FR-4']));
        $this->assertEquals('internal', $res1['quotation_source']);
        $this->assertEquals('normal', $res1['order_type']);
        $this->assertEquals('M', $res1['series']);

        // 2 layer FR-4
        $res2 = OrderPricingService::resolveOrderSource(['layers' => '2 Layers', 'base_material' => 'FR-4']);
        $this->assertFalse(OrderPricingService::isJlcpcbRequired(['layers' => '2 Layers', 'base_material' => 'FR-4']));
        $this->assertEquals('internal', $res2['quotation_source']);
        $this->assertEquals('normal', $res2['order_type']);
        $this->assertEquals('M', $res2['series']);
    }

    public function test_multilayer_boards_greater_than_2_layers_use_jlcpcb_and_jl_series(): void
    {
        // 4 layers
        $res4 = OrderPricingService::resolveOrderSource(['layers' => 4, 'base_material' => 'FR-4']);
        $this->assertTrue(OrderPricingService::isJlcpcbRequired(['layers' => 4, 'base_material' => 'FR-4']));
        $this->assertEquals('jlcpcb', $res4['quotation_source']);
        $this->assertEquals('jlcpcb', $res4['order_type']);
        $this->assertEquals('JL', $res4['series']);

        // 6 layers
        $res6 = OrderPricingService::resolveOrderSource(['layers' => '6', 'base_material' => 'FR-4']);
        $this->assertTrue(OrderPricingService::isJlcpcbRequired(['layers' => '6', 'base_material' => 'FR-4']));
        $this->assertEquals('jlcpcb', $res6['quotation_source']);
        $this->assertEquals('jlcpcb', $res6['order_type']);
        $this->assertEquals('JL', $res6['series']);
    }

    public function test_stale_jlcpcb_flag_and_file_keys_cannot_override_2_layer_local_order(): void
    {
        // CRITICAL REGRESSION TEST:
        // User had 4 layers (got JLCPCB quote + file key), then changed to 2 layers.
        // Frontend sends stale quotation_source: jlcpcb and jlcpcb_file_key:
        $stalePayload = [
            'layers' => 2,
            'base_material' => 'FR-4',
            'quotation_source' => 'jlcpcb',
            'order_type' => 'jlcpcb',
            'fileKey' => 'uploaded_file_key_123',
            'jlcpcb_file_key' => 'jlc_key_abc',
        ];

        $this->assertFalse(OrderPricingService::isJlcpcbRequired($stalePayload));
        $res = OrderPricingService::resolveOrderSource($stalePayload);
        $this->assertEquals('internal', $res['quotation_source']);
        $this->assertEquals('normal', $res['order_type']);
        $this->assertEquals('M', $res['series']);
    }

    public function test_stale_internal_flag_cannot_override_4_layer_jlcpcb_order(): void
    {
        $staleInternalPayload = [
            'layers' => 4,
            'base_material' => 'FR-4',
            'quotation_source' => 'internal',
            'order_type' => 'normal',
        ];

        $this->assertTrue(OrderPricingService::isJlcpcbRequired($staleInternalPayload));
        $res = OrderPricingService::resolveOrderSource($staleInternalPayload);
        $this->assertEquals('jlcpcb', $res['quotation_source']);
        $this->assertEquals('jlcpcb', $res['order_type']);
        $this->assertEquals('JL', $res['series']);
    }

    public function test_non_fr4_and_stencil_rules(): void
    {
        // Flex PCB (even with 2 layers) requires JLCPCB
        $flexRes = OrderPricingService::resolveOrderSource(['layers' => 2, 'base_material' => 'Flex']);
        $this->assertTrue(OrderPricingService::isJlcpcbRequired(['layers' => 2, 'base_material' => 'Flex']));
        $this->assertEquals('jlcpcb', $flexRes['quotation_source']);
        $this->assertEquals('JL', $flexRes['series']);

        // Stencil (even with 4 layers) is Local
        $stencilRes = OrderPricingService::resolveOrderSource(['layers' => 4, 'product_type' => 'stencil']);
        $this->assertFalse(OrderPricingService::isJlcpcbRequired(['layers' => 4, 'product_type' => 'stencil']));
        $this->assertEquals('internal', $stencilRes['quotation_source']);
        $this->assertEquals('M', $stencilRes['series']);
    }
}

