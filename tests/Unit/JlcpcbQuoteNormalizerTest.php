<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\JlcpcbQuoteNormalizer;

class JlcpcbQuoteNormalizerTest extends TestCase
{
    /**
     * Test Case 1 from real JLCPCB API response & official website UI:
     * - projectFee: 8.00
     * - viaCoveringMoney: 0.00
     * - adornPutFee: 253.10
     * - fillFee: 2.80
     * - stencilFee: 3729.40
     * - CPF (Confirm Production file): 1.05
     * - testsFee: 430.10
     * - totalFee: 4423.40
     * Expected Base PCB Price: 3994.35
     * Expected Provider totalFee: 4423.40
     */
    public function test_normalizer_test_case_1(): void
    {
        $normalizer = new JlcpcbQuoteNormalizer();

        $apiResponse1 = [
            'orderTotalWeight' => 166000,
            'priceWithoutFreight' => 4423.40,
            'weight' => 166,
            'chargeWeight' => 174415.5,
            'pcbCostInfo' => [
                'projectFee' => 8,
                'spellFee' => 0,
                'adornPutFee' => 253.10,
                'stencilFee' => 3729.40,
                'testsFee' => 430.10,
                'fillFee' => 2.80,
                'achieveFee' => 0,
                'viaCoveringMoney' => 0,
                'goldThicknessMoney' => 0,
                'edgeGrindingMoney' => 0,
                'dummyMoney' => 4423.40,
                'specialMoney' => 0,
                'totalFee' => 4423.40
            ],
            'serviceConfigFeeInfo' => [
                [
                    'serviceConfigCode' => 'CPF',
                    'serviceConfigShow' => 'Confirm Production file',
                    'serviceFee' => 1.05
                ]
            ],
            'shipList' => [
                [
                    'options' => 'UPS EXPRESS',
                    'showOptions' => 'UPS Worldwide Express Saver',
                    'cost' => '986.7500',
                    'day' => '6-9 business days'
                ],
                [
                    'options' => 'DHL Express',
                    'showOptions' => 'DHL Express',
                    'cost' => '1555.3600',
                    'day' => '2-4 business days'
                ],
                [
                    'options' => 'Sea Shipment',
                    'showOptions' => 'Sea Shipment',
                    'cost' => '378.8100',
                    'day' => '25-35 business days'
                ]
            ],
            'achieveDateList' => [
                ['achieveName' => '5-6 days', 'achieveDate' => '120', 'achieveChecked' => 'checked', 'achievePrice' => 0],
                ['achieveName' => '3-4 days', 'achieveDate' => '70', 'achieveChecked' => '', 'achievePrice' => 0]
            ]
        ];

        $payload1 = [
            'pcbParam' => [
                'surfaceFinish' => 1, // LeadFree HASL
                'serviceConfigVos' => [
                    ['serviceConfigCode' => 'CPF', 'configOptionShow' => 'Yes']
                ]
            ]
        ];

        $normalized = $normalizer->normalize($apiResponse1, $payload1);

        $this->assertEquals(3994.35, $normalized['basePcbPrice']);
        $this->assertEquals(4423.40, $normalized['providerTotalFee']);
        $this->assertNotEquals($normalized['basePcbPrice'], $normalized['providerTotalFee']);

        // Verify individual charges
        $chargesByCode = [];
        foreach ($normalized['pcbCharges'] as $c) {
            $chargesByCode[$c['code']] = $c;
        }

        $this->assertEquals(8.00, $chargesByCode['engineering_fee']['amount']);
        $this->assertEquals(0.00, $chargesByCode['via_covering']['amount']);
        $this->assertEquals(253.10, $chargesByCode['surface_finish']['amount']);
        $this->assertEquals(2.80, $chargesByCode['film']['amount']);
        $this->assertEquals(3729.40, $chargesByCode['board']['amount']);
        $this->assertEquals(1.05, $chargesByCode['confirm_production_file']['amount']);

        // Verify shipping is kept separate
        $this->assertCount(3, $normalized['shippingOptions']);
        $this->assertEquals(986.75, $normalized['shippingOptions'][0]['price']);
    }

    /**
     * Test Case 2 from real JLCPCB API response & official website UI:
     * - projectFee: 8.00
     * - adornPutFee: 14.90
     * - stencilFee: 149.20
     * - fillFee: 2.80
     * - viaCoveringMoney: 0.00
     * - testsFee: 33.10
     * - totalFee: 208.00
     * Official UI expected:
     * Expected Base PCB Price: 160.00
     * Expected Provider totalFee: 208.00
     */
    public function test_normalizer_test_case_2(): void
    {
        $normalizer = new JlcpcbQuoteNormalizer();

        $apiResponse2 = [
            'priceWithoutFreight' => 208.00,
            'pcbCostInfo' => [
                'projectFee' => 8.00,
                'adornPutFee' => 14.90,
                'stencilFee' => 149.20,
                'testsFee' => 33.10,
                'fillFee' => 2.80,
                'viaCoveringMoney' => 0,
                'totalFee' => 208.00
            ]
        ];

        $payload2 = [
            'pcbParam' => [
                'surfaceFinish' => 0 // HASL with lead requested by user
            ]
        ];

        $normalized = $normalizer->normalize($apiResponse2, $payload2);

        $this->assertEquals(160.00, $normalized['basePcbPrice']);
        $this->assertEquals(208.00, $normalized['providerTotalFee']);
        $this->assertNotEquals($normalized['basePcbPrice'], $normalized['providerTotalFee']);

        // Verify individual charges
        $chargesByCode = [];
        foreach ($normalized['pcbCharges'] as $c) {
            $chargesByCode[$c['code']] = $c;
        }

        $this->assertEquals(8.00, $chargesByCode['engineering_fee']['amount']);
        $this->assertEquals(0.00, $chargesByCode['via_covering']['amount']);
        $this->assertEquals(0.00, $chargesByCode['surface_finish']['amount']);
        $this->assertEquals(2.80, $chargesByCode['film']['amount']);
        $this->assertEquals(149.20, $chargesByCode['board']['amount']);
    }
}
