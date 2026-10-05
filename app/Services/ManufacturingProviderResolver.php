<?php

namespace App\Services;

class ManufacturingProviderResolver
{
    public const PROVIDER_IN_HOUSE = 'IN_HOUSE';
    public const PROVIDER_JLCPCB = 'JLCPCB';

    /**
     * Authoritatively resolve manufacturing provider, eligibility, and quotation routing.
     *
     * A PCB qualifies for IN-HOUSE manufacturing ONLY WHEN ALL 14 mandatory conditions are satisfied.
     * If even ONE condition fails, the PCB must be routed to JLCPCB.
     *
     * Canonical Business Rules:
     * 1. Base Material: FR-4 only.
     * 2. Material Type: FR4 TG135 only (KB6164, Nan Ya, S1141, S1000H, etc. force JLCPCB).
     * 3. Layers: 1 or 2 layers only (4, 6, 8, 10, 12, 14, 16 force JLCPCB).
     * 4. Surface Finish: HASL (Leaded) only (LeadFree HASL, ENIG, OSP force JLCPCB).
     * 5. Thickness: Any supported thickness EXCEPT 0.6 mm (0.6 mm forces JLCPCB).
     * 6. PCB Color: All supported colors qualify (Green, Red, Yellow, Blue, White, Black, Purple).
     * 7. Copper Weight: All supported weights qualify (1 oz, 2 oz).
     * 8. Min Via Hole: Must be >= 0.30 mm (< 0.30 mm e.g. 0.25, 0.20, 0.15 force JLCPCB).
     * 9. Gold Fingers: No (Yes forces JLCPCB).
     * 10. Castellated Holes: No (Yes forces JLCPCB).
     * 11. Edge Plating: No (Yes forces JLCPCB).
     * 12. Blind Slots: No (Yes forces JLCPCB).
     * 13. Via Covering: Tented, Untented, Not Specified qualify.
     *     Plugged, Epoxy Filled & Capped, Copper Paste Filled & Capped force JLCPCB.
     * 14. Via Plating: Not Specified qualifies.
     *     Conductive Adhesive, Horizontal Electroless Copper Plating force JLCPCB.
     *
     * Non-PCB products (parts, stencils) always route to local quotation and normal order series.
     *
     * @param array $data PCB specifications or request payload
     * @return array [
     *     'provider' => 'IN_HOUSE'|'JLCPCB',
     *     'is_eligible' => bool,
     *     'reasons' => string[],
     *     'quotation_source' => 'internal'|'jlcpcb',
     *     'order_type' => 'normal'|'jlcpcb',
     *     'series' => 'M'|'JL'
     * ]
     */
    public static function resolve(array $data): array
    {
        $productType = strtolower(trim((string)($data['productType'] ?? $data['product_type'] ?? 'pcb')));
        if ($productType === 'part' || $productType === 'stencil') {
            return [
                'provider' => self::PROVIDER_IN_HOUSE,
                'is_eligible' => true,
                'reasons' => [],
                'quotation_source' => 'internal',
                'order_type' => 'normal',
                'series' => 'M',
            ];
        }

        $reasons = [];

        // 1. Base Material Check: Must be FR-4
        $rawMat = trim((string)($data['baseMaterial'] ?? $data['base_material'] ?? $data['material'] ?? 'FR-4'));
        $matNorm = strtolower(str_replace([' ', '-', '_'], '', $rawMat));
        $isFr4 = ($matNorm === '' || $matNorm === 'fr4' || str_starts_with($matNorm, 'fr4'));
        if (!$isFr4) {
            $reasons[] = "Base Material: '{$rawMat}' is not eligible for In-House (FR-4 only).";
        }

        // 2. Material Type Check: Only FR4 TG135 qualifies
        // Default to FR4 TG135 if not specified and base material is FR-4
        $rawMt = trim((string)($data['materialType'] ?? $data['material_type'] ?? ($isFr4 ? 'FR4 TG135' : '')));
        $mtNorm = strtolower(str_replace([' ', '-', '_'], '', $rawMt));
        $isFr4Tg135 = ($mtNorm === '' || $mtNorm === 'fr4tg135' || $mtNorm === 'tg135');
        if (!$isFr4Tg135) {
            $reasons[] = "Material Type: '{$rawMt}' requires JLCPCB (In-House supports FR4 TG135 only).";
        }

        // 3. Layer Count Check: Only 1 and 2 layers qualify
        $rawLayers = (string)($data['layers'] ?? $data['layer'] ?? $data['layer_count'] ?? $data['layerCount'] ?? '2');
        $layers = (int) preg_replace('/[^0-9]/', '', $rawLayers);
        if ($layers <= 0) {
            $layers = 2;
        }
        if ($layers !== 1 && $layers !== 2) {
            $reasons[] = "Layer Count: {$layers} Layers requires JLCPCB (In-House supports 1L & 2L only).";
        }

        // 4. Surface Finish Check: Only HASL (Leaded) qualifies
        $rawSf = trim((string)($data['surfaceFinish'] ?? $data['surface_finish'] ?? 'HASL(Leaded)'));
        $sfLower = strtolower($rawSf);
        $isLeadFreeOrEnigOrOsp = str_contains($sfLower, 'free') ||
                                str_contains($sfLower, 'enig') ||
                                str_contains($sfLower, 'osp') ||
                                str_contains($sfLower, 'gold') ||
                                str_contains($sfLower, 'immersion') ||
                                str_contains($sfLower, 'rohs');
        $isLeadedHasl = !$isLeadFreeOrEnigOrOsp && (
            $sfLower === '' ||
            str_contains($sfLower, 'hasl') ||
            str_contains($sfLower, 'leaded') ||
            str_contains($sfLower, 'with lead')
        );
        if (!$isLeadedHasl) {
            $reasons[] = "Surface Finish: '{$rawSf}' requires JLCPCB (In-House supports HASL Leaded only).";
        }

        // 5. PCB Thickness Check: Any supported thickness except 0.6 mm
        $rawThickness = (string)($data['thickness'] ?? $data['board_thickness'] ?? '1.6mm');
        if (preg_match('/(\d+(?:\.\d+)?)/', $rawThickness, $tMatches)) {
            $thicknessVal = (float)$tMatches[1];
            if (abs($thicknessVal - 0.6) < 0.05) {
                $reasons[] = "PCB Thickness: 0.6mm requires JLCPCB.";
            }
        }

        // 6. PCB Color / Solder Mask: All supported colors qualify, does not force JLCPCB.
        // 7. Outer Copper Weight: All supported weights qualify (1 oz, 2 oz), does not force JLCPCB.

        // 8. Minimum Via Hole: Must be >= 0.30 mm
        $rawMinHole = trim((string)($data['minHole'] ?? $data['min_hole'] ?? $data['minimum_via_hole'] ?? '0.3mm'));
        if (preg_match('/(\d+(?:\.\d+)?)/', $rawMinHole, $hMatches)) {
            $holeVal = (float)$hMatches[1];
            if ($holeVal < 0.299) {
                $reasons[] = "Min Via Hole: '{$rawMinHole}' ({$holeVal}mm < 0.30mm) requires JLCPCB.";
            }
        }

        // 9. Gold Fingers: No
        $gf = self::parseBooleanOption($data['goldFingers'] ?? $data['gold_fingers'] ?? $data['goldFinger'] ?? null);
        if ($gf) {
            $reasons[] = "Gold Fingers: Yes requires JLCPCB.";
        }

        // 10. Castellated Holes: No
        $ch = self::parseBooleanOption($data['castellated'] ?? $data['castellatedHoles'] ?? $data['castellated_holes'] ?? null);
        if ($ch) {
            $reasons[] = "Castellated Holes: Yes requires JLCPCB.";
        }

        // 11. Edge Plating: No
        $ep = self::parseBooleanOption($data['edgePlating'] ?? $data['edge_plating'] ?? $data['edgeRounding'] ?? null);
        if ($ep) {
            $reasons[] = "Edge Plating: Yes requires JLCPCB.";
        }

        // 12. Blind Slots: No
        $bs = self::parseBooleanOption($data['blindSlots'] ?? $data['blind_slots'] ?? null);
        if ($bs) {
            $reasons[] = "Blind Slots: Yes requires JLCPCB.";
        }

        // 13. Via Covering: Plugged, Epoxy Filled & Capped, Copper Paste Filled & Capped force JLCPCB
        $rawVc = trim((string)($data['viaCovering'] ?? $data['via_covering'] ?? ''));
        $vcLower = strtolower($rawVc);
        if (str_contains($vcLower, 'plugged')) {
            $reasons[] = "Via Covering: Plugged requires JLCPCB.";
        } elseif (str_contains($vcLower, 'epoxy')) {
            $reasons[] = "Via Covering: Epoxy Filled & Capped requires JLCPCB.";
        } elseif (str_contains($vcLower, 'copper') && (str_contains($vcLower, 'paste') || str_contains($vcLower, 'fill'))) {
            $reasons[] = "Via Covering: Copper Paste Filled & Capped requires JLCPCB.";
        }

        // 14. Via Plating Method: Conductive Adhesive, Horizontal Electroless Copper force JLCPCB
        $rawVp = trim((string)($data['viaPlating'] ?? $data['via_plating'] ?? $data['viaPlatingMethod'] ?? ''));
        $vpLower = strtolower($rawVp);
        if (str_contains($vpLower, 'conductive') && str_contains($vpLower, 'adhesive')) {
            $reasons[] = "Via Plating: Conductive Adhesive requires JLCPCB.";
        } elseif (str_contains($vpLower, 'horizontal') || str_contains($vpLower, 'electroless')) {
            $reasons[] = "Via Plating: Horizontal Electroless Copper Plating requires JLCPCB.";
        }

        $isEligible = empty($reasons);

        if ($isEligible) {
            return [
                'provider' => self::PROVIDER_IN_HOUSE,
                'is_eligible' => true,
                'reasons' => [],
                'quotation_source' => 'internal',
                'order_type' => 'normal',
                'series' => 'M',
            ];
        }

        return [
            'provider' => self::PROVIDER_JLCPCB,
            'is_eligible' => false,
            'reasons' => $reasons,
            'quotation_source' => 'jlcpcb',
            'order_type' => 'jlcpcb',
            'series' => 'JL',
        ];
    }

    /**
     * Check if a PCB configuration requires JLCPCB routing.
     */
    public static function isJlcpcbRequired(array $data): bool
    {
        $res = self::resolve($data);
        return $res['provider'] === self::PROVIDER_JLCPCB;
    }

    /**
     * Check if a PCB configuration is eligible for IN-HOUSE manufacturing.
     */
    public static function isEligibleForInHouse(array $data): bool
    {
        $res = self::resolve($data);
        return $res['is_eligible'];
    }

    /**
     * Parse truthy/falsy boolean form fields.
     */
    private static function parseBooleanOption(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_bool($value)) {
            return $value;
        }
        $str = strtolower(trim((string)$value));
        return in_array($str, ['yes', 'true', '1', 'on'], true);
    }
}
