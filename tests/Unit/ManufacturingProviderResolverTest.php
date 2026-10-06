<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\ManufacturingProviderResolver;
use App\Services\OrderPricingService;

class ManufacturingProviderResolverTest extends TestCase
{
    /**
     * Canonical baseline configuration that satisfies ALL 14 business rules for IN-HOUSE manufacturing.
     */
    private function getBaselineInHouseSpecs(): array
    {
        return [
            'product_type' => 'pcb',
            'base_material' => 'FR-4',
            'material_type' => 'FR4 TG135',
            'layers' => 2,
            'thickness' => '1.6mm',
            'surface_finish' => 'HASL(Leaded)',
            'pcb_color' => 'Green',
            'copper_weight' => '1 oz',
            'min_hole' => '0.3mm/(0.4/0.45mm)',
            'gold_fingers' => 'No',
            'castellated' => 'No',
            'edge_plating' => 'No',
            'blind_slots' => 'No',
            'via_covering' => 'Tented',
            'via_plating' => 'Not Specified',
        ];
    }

    public function test_baseline_qualifies_for_in_house(): void
    {
        $specs = $this->getBaselineInHouseSpecs();
        $this->assertTrue(ManufacturingProviderResolver::isEligibleForInHouse($specs));
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired($specs));

        $res = ManufacturingProviderResolver::resolve($specs);
        $this->assertEquals(ManufacturingProviderResolver::PROVIDER_IN_HOUSE, $res['provider']);
        $this->assertTrue($res['is_eligible']);
        $this->assertEmpty($res['reasons']);
        $this->assertEquals('internal', $res['quotation_source']);
        $this->assertEquals('normal', $res['order_type']);
        $this->assertEquals('M', $res['series']);
    }

    // Rule 1: Base Material (FR-4 only)
    public function test_rule_1_base_material(): void
    {
        $base = $this->getBaselineInHouseSpecs();

        // FR-4 qualifies
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['base_material' => 'FR-4'])));
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['base_material' => 'FR4'])));

        // Non-FR4 forces JLCPCB
        $nonFr4 = ['Flex', 'Rogers', 'PTFE Teflon', 'Aluminum'];
        foreach ($nonFr4 as $mat) {
            $this->assertTrue(
                ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['base_material' => $mat])),
                "Base material {$mat} must force JLCPCB"
            );
        }
    }

    // Rule 2: Material Type (FR4 TG135 only)
    public function test_rule_2_material_type(): void
    {
        $base = $this->getBaselineInHouseSpecs();

        // FR4 TG135 qualifies
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['material_type' => 'FR4 TG135'])));
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['material_type' => 'FR4-TG135'])));

        // Other material types force JLCPCB even on FR-4
        $otherTypes = ['KB6164 - TG135', 'Nan Ya NP-140F', 'S1141 TG140', 'S1000H TG155', 'Any Other Type'];
        foreach ($otherTypes as $mt) {
            $specs = array_merge($base, ['material_type' => $mt]);
            $this->assertTrue(
                ManufacturingProviderResolver::isJlcpcbRequired($specs),
                "Material type {$mt} must force JLCPCB"
            );
            $res = ManufacturingProviderResolver::resolve($specs);
            $this->assertEquals('JL', $res['series']);
        }
    }

    // Rule 3: Layers (1L & 2L only)
    public function test_rule_3_pcb_layers(): void
    {
        $base = $this->getBaselineInHouseSpecs();

        // 1 & 2 layers qualify
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['layers' => 1])));
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['layers' => 2])));
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['layers' => '2 Layers'])));

        // > 2 layers force JLCPCB
        $multilayer = [4, 6, 8, 10, 12, 14, 16];
        foreach ($multilayer as $l) {
            $this->assertTrue(
                ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['layers' => $l])),
                "{$l} layers must force JLCPCB"
            );
        }
    }

    // Rule 4: Surface Finish (HASL Leaded only)
    public function test_rule_4_surface_finish(): void
    {
        $base = $this->getBaselineInHouseSpecs();

        // HASL Leaded qualifies
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['surface_finish' => 'HASL(Leaded)'])));
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['surface_finish' => 'HASL(with lead)'])));

        // LeadFree HASL, ENIG, OSP force JLCPCB
        $otherFinishes = ['LeadFree HASL', 'LeadFree HASL (RoHS)', 'ENIG', 'OSP'];
        foreach ($otherFinishes as $sf) {
            $specs = array_merge($base, ['surface_finish' => $sf]);
            $this->assertTrue(
                ManufacturingProviderResolver::isJlcpcbRequired($specs),
                "Surface finish {$sf} must force JLCPCB"
            );
        }
    }

    // Rule 5: Thickness (Except 0.6 mm)
    public function test_rule_5_thickness(): void
    {
        $base = $this->getBaselineInHouseSpecs();

        // 0.6 mm forces JLCPCB
        $this->assertTrue(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['thickness' => '0.6mm'])));
        $this->assertTrue(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['thickness' => '0.6'])));

        // Other supported thicknesses qualify
        $supported = ['0.8mm', '1.0mm', '1.2mm', '1.6mm', '2.0mm'];
        foreach ($supported as $t) {
            $this->assertFalse(
                ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['thickness' => $t])),
                "Thickness {$t} should qualify for IN-HOUSE"
            );
        }
    }

    // Rule 6: Color (All supported colors qualify)
    public function test_rule_6_pcb_color(): void
    {
        $base = $this->getBaselineInHouseSpecs();
        $colors = ['Green', 'Purple', 'Red', 'Yellow', 'Blue', 'White', 'Black'];
        foreach ($colors as $color) {
            $this->assertFalse(
                ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['pcb_color' => $color])),
                "Color {$color} must qualify for IN-HOUSE"
            );
        }
    }

    // Rule 7: Copper Weight (All supported weights qualify)
    public function test_rule_7_copper_weight(): void
    {
        $base = $this->getBaselineInHouseSpecs();
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['copper_weight' => '1 oz'])));
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['copper_weight' => '2 oz'])));
    }

    // Rule 8: Minimum Via Hole (>= 0.30 mm)
    public function test_rule_8_minimum_via_hole(): void
    {
        $base = $this->getBaselineInHouseSpecs();

        // 0.30 mm qualifies
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['min_hole' => '0.3mm/(0.4/0.45mm)'])));
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['min_hole' => '0.3mm'])));
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['min_hole' => '0.30mm'])));

        // < 0.30 mm forces JLCPCB
        $smallHoles = ['0.25mm/(0.35/0.4mm)', '0.2mm/(0.3/0.35mm)', '0.15mm/(0.25/0.3mm)', '0.25mm', '0.20mm', '0.15mm'];
        foreach ($smallHoles as $h) {
            $this->assertTrue(
                ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['min_hole' => $h])),
                "Hole {$h} must force JLCPCB"
            );
        }
    }

    // Rule 9: Gold Fingers (No)
    public function test_rule_9_gold_fingers(): void
    {
        $base = $this->getBaselineInHouseSpecs();
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['gold_fingers' => 'No'])));
        $this->assertTrue(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['gold_fingers' => 'Yes'])));
    }

    // Rule 10: Castellated Holes (No)
    public function test_rule_10_castellated_holes(): void
    {
        $base = $this->getBaselineInHouseSpecs();
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['castellated' => 'No'])));
        $this->assertTrue(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['castellated' => 'Yes'])));
    }

    // Rule 11: Edge Plating (No)
    public function test_rule_11_edge_plating(): void
    {
        $base = $this->getBaselineInHouseSpecs();
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['edge_plating' => 'No'])));
        $this->assertTrue(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['edge_plating' => 'Yes'])));
    }

    // Rule 12: Blind Slots (No)
    public function test_rule_12_blind_slots(): void
    {
        $base = $this->getBaselineInHouseSpecs();
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['blind_slots' => 'No'])));
        $this->assertTrue(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['blind_slots' => 'Yes'])));
    }

    // Rule 13: Via Covering (Tented, Untented qualify; Plugged, Epoxy, Copper Paste force JLCPCB)
    public function test_rule_13_via_covering(): void
    {
        $base = $this->getBaselineInHouseSpecs();

        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['via_covering' => 'Tented'])));
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['via_covering' => 'Untented'])));
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['via_covering' => 'Not Specified'])));

        $forbidden = ['Plugged', 'Epoxy Filled & Capped', 'Copper Paste Filled & Capped', 'Copper paste Filled & Capped'];
        foreach ($forbidden as $vc) {
            $this->assertTrue(
                ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['via_covering' => $vc])),
                "Via covering {$vc} must force JLCPCB"
            );
        }
    }

    // Rule 14: Via Plating Method (Not Specified qualifies; Conductive Adhesive, Horizontal Electroless force JLCPCB)
    public function test_rule_14_via_plating(): void
    {
        $base = $this->getBaselineInHouseSpecs();

        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['via_plating' => 'Not Specified'])));
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['via_plating' => ''])));

        $forbidden = ['Conductive Adhesive', 'Horizontal Electroless Copper Plating'];
        foreach ($forbidden as $vp) {
            $this->assertTrue(
                ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['via_plating' => $vp])),
                "Via plating {$vp} must force JLCPCB"
            );
        }
    }

    // Rule 15 & 16: Mark on PCB and Electrical Test are permanently removed and do not force JLCPCB
    public function test_mark_on_pcb_and_electrical_test_do_not_force_jlcpcb(): void
    {
        $base = $this->getBaselineInHouseSpecs();

        // Even with previously forbidden values, in-house remains valid
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['mark_on_pcb' => 'Remove Mark'])));
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['markOnPcb' => 'Remove Mark'])));
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['elecTest' => 'Flying Probe Fully Test'])));
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['elec_test' => 'Flying Probe Fully Test'])));
    }

    // Transition & Regression Scenarios
    public function test_transition_scenarios(): void
    {
        $base = $this->getBaselineInHouseSpecs();

        // 2L -> 4L -> 2L
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired($base));
        $this->assertTrue(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['layers' => 4])));
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['layers' => 2])));

        // HASL Leaded -> ENIG -> HASL Leaded
        $this->assertTrue(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['surface_finish' => 'ENIG'])));
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['surface_finish' => 'HASL(Leaded)'])));

        // FR4 TG135 -> KB6164 TG135 -> FR4 TG135
        $this->assertTrue(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['material_type' => 'KB6164 - TG135'])));
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['material_type' => 'FR4 TG135'])));

        // 1.6mm -> 0.6mm -> 1.6mm
        $this->assertTrue(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['thickness' => '0.6mm'])));
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['thickness' => '1.6mm'])));

        // Min hole 0.30mm -> 0.20mm -> 0.30mm
        $this->assertTrue(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['min_hole' => '0.20mm'])));
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired(array_merge($base, ['min_hole' => '0.30mm'])));

        // Stale flags do NOT override 2-layer local board
        $stale = array_merge($base, [
            'quotation_source' => 'jlcpcb',
            'order_type' => 'jlcpcb',
            'jlcpcb_file_key' => 'jlc_key_123',
        ]);
        $this->assertFalse(ManufacturingProviderResolver::isJlcpcbRequired($stale));
        $res = OrderPricingService::resolveOrderSource($stale);
        $this->assertEquals('internal', $res['quotation_source']);
        $this->assertEquals('normal', $res['order_type']);
        $this->assertEquals('M', $res['series']);
    }
}
