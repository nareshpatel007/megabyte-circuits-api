<?php

namespace App\Services;

use App\Models\PcbProviderRule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;

class ManufacturingProviderResolver
{
    public const PROVIDER_IN_HOUSE = 'IN_HOUSE';
    public const PROVIDER_JLCPCB = 'JLCPCB';
    public const CACHE_KEY = 'pcb_provider_rules_active';

    /**
     * Authoritatively resolve manufacturing provider, eligibility, and quotation routing.
     * Evaluates database-driven routing rules in priority order.
     *
     * Non-PCB products (parts, stencils) always route to local quotation and normal order series.
     *
     * @param array $data PCB specifications or request payload
     * @return array [
     *     'provider' => 'IN_HOUSE'|'JLCPCB',
     *     'is_eligible' => bool,
     *     'rule_id' => int|null,
     *     'rule_name' => string|null,
     *     'rule_slug' => string|null,
     *     'matched_conditions' => array,
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
                'rule_id' => null,
                'rule_name' => 'Non-PCB Product Rule',
                'rule_slug' => 'non_pcb_local',
                'matched_conditions' => ['Product Type: ' . $productType],
                'reasons' => [],
                'quotation_source' => 'internal',
                'order_type' => 'normal',
                'series' => 'M',
            ];
        }

        // 1. Attempt database-driven resolution
        try {
            $dbResult = self::resolveFromDatabase($data);
            if ($dbResult !== null) {
                return $dbResult;
            }
        } catch (\Throwable $e) {
            // Log warning and safely fallback to hardcoded canonical rules
            \Illuminate\Support\Facades\Log::warning("Provider rule database evaluation failed: " . $e->getMessage());
        }

        // 2. Canonical hardcoded fallback (ensures zero downtime if database is unreachable)
        return self::resolveCanonicalHardcoded($data);
    }

    /**
     * Resolve provider from active database rules.
     */
    public static function resolveFromDatabase(array $data): ?array
    {
        if (
            !class_exists(\Illuminate\Support\Facades\Facade::class) ||
            !\Illuminate\Support\Facades\Facade::getFacadeApplication() ||
            !\Illuminate\Support\Facades\Schema::hasTable('pcb_provider_rules') ||
            !\Illuminate\Support\Facades\Schema::hasTable('pcb_provider_rule_conditions')
        ) {
            return null;
        }

        $rules = self::getActiveRules();
        if ($rules->isEmpty()) {
            return null;
        }

        $allReasons = [];

        foreach ($rules as $rule) {
            $isMatch = false;
            $matchedConditions = [];
            $ruleFailures = [];

            $matchType = strtoupper($rule->match_type ?? 'ALL');
            $conditions = $rule->conditions;

            if ($conditions->isEmpty()) {
                // An active rule with no conditions matches unconditionally
                $isMatch = true;
            } else {
                $passedCount = 0;
                $totalCount = $conditions->count();

                foreach ($conditions as $cond) {
                    $field = $cond->field;
                    $operator = $cond->operator;
                    $expectedValue = $cond->value;

                    $actualValue = self::extractFieldValue($field, $data);
                    $pass = self::evaluateCondition($field, $actualValue, $operator, $expectedValue);

                    $condDescription = self::formatConditionDescription($field, $operator, $expectedValue, $actualValue);

                    if ($pass) {
                        $passedCount++;
                        $matchedConditions[] = $condDescription;
                    } else {
                        $ruleFailures[] = $condDescription;
                    }
                }

                if ($matchType === 'ANY') {
                    $isMatch = $passedCount > 0;
                } else { // ALL
                    $isMatch = $passedCount === $totalCount;
                }
            }

            if ($isMatch) {
                $provider = strtoupper(trim($rule->provider)) === 'JLCPCB' ? self::PROVIDER_JLCPCB : self::PROVIDER_IN_HOUSE;
                $isEligible = ($provider === self::PROVIDER_IN_HOUSE);

                return [
                    'provider' => $provider,
                    'is_eligible' => $isEligible,
                    'rule_id' => $rule->id,
                    'rule_name' => $rule->name,
                    'rule_slug' => $rule->slug,
                    'matched_conditions' => $matchedConditions,
                    'reasons' => $provider === self::PROVIDER_JLCPCB ? ($ruleFailures ?: ["Matched JLCPCB rule: {$rule->name}"]) : [],
                    'quotation_source' => $isEligible ? 'internal' : 'jlcpcb',
                    'order_type' => $isEligible ? 'normal' : 'jlcpcb',
                    'series' => $isEligible ? 'M' : 'JL',
                ];
            } else {
                foreach ($ruleFailures as $failMsg) {
                    $allReasons[] = $failMsg;
                }
            }
        }

        // Phase 7: JLCPCB is the fallback when no In-House rule matches
        return [
            'provider' => self::PROVIDER_JLCPCB,
            'is_eligible' => false,
            'rule_id' => null,
            'rule_name' => 'JLCPCB Default Fallback',
            'rule_slug' => 'jlcpcb_default_fallback',
            'matched_conditions' => [],
            'reasons' => array_values(array_unique($allReasons)),
            'quotation_source' => 'jlcpcb',
            'order_type' => 'jlcpcb',
            'series' => 'JL',
        ];
    }

    /**
     * Retrieve active rules cached.
     */
    public static function getActiveRules()
    {
        return Cache::remember(self::CACHE_KEY, 3600, function () {
            return PcbProviderRule::active()
                ->ordered()
                ->with(['conditions' => function ($q) {
                    $q->orderBy('sort_order', 'asc');
                }])
                ->get();
        });
    }

    /**
     * Invalidate active rules cache.
     */
    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Extract normalized field value from configuration array.
     */
    public static function extractFieldValue(string $field, array $data): mixed
    {
        switch ($field) {
            case 'base_material':
            case 'baseMaterial':
            case 'material':
                return trim((string)($data['baseMaterial'] ?? $data['base_material'] ?? $data['material'] ?? 'FR-4'));

            case 'material_type':
            case 'materialType':
                $rawMat = trim((string)($data['baseMaterial'] ?? $data['base_material'] ?? $data['material'] ?? 'FR-4'));
                $matNorm = strtolower(str_replace([' ', '-', '_'], '', $rawMat));
                $isFr4 = ($matNorm === '' || $matNorm === 'fr4' || str_starts_with($matNorm, 'fr4'));
                return trim((string)($data['materialType'] ?? $data['material_type'] ?? ($isFr4 ? 'FR4 TG135' : '')));

            case 'layers':
            case 'layer':
            case 'layer_count':
            case 'layerCount':
                $rawLayers = (string)($data['layers'] ?? $data['layer'] ?? $data['layer_count'] ?? $data['layerCount'] ?? '2');
                $layers = (int) preg_replace('/[^0-9]/', '', $rawLayers);
                return $layers > 0 ? $layers : 2;

            case 'surface_finish':
            case 'surfaceFinish':
                return trim((string)($data['surfaceFinish'] ?? $data['surface_finish'] ?? 'HASL(Leaded)'));

            case 'thickness':
            case 'board_thickness':
                $rawThickness = (string)($data['thickness'] ?? $data['board_thickness'] ?? '1.6mm');
                if (preg_match('/(\d+(?:\.\d+)?)/', $rawThickness, $tMatches)) {
                    return (float)$tMatches[1];
                }
                return 1.6;

            case 'min_hole':
            case 'minHole':
            case 'min_via_hole':
            case 'minViaHole':
            case 'minimum_via_hole':
            case 'minimumViaHole':
                $rawMinHole = trim((string)($data['minHole'] ?? $data['min_hole'] ?? $data['min_via_hole'] ?? $data['minimum_via_hole'] ?? '0.3mm'));
                if (preg_match('/(\d+(?:\.\d+)?)/', $rawMinHole, $hMatches)) {
                    return (float)$hMatches[1];
                }
                return 0.3;

            case 'pcb_color':
            case 'pcbColor':
                return trim((string)($data['pcbColor'] ?? $data['pcb_color'] ?? 'Green'));

            case 'copper_weight':
            case 'copperWeight':
                return trim((string)($data['copperWeight'] ?? $data['copper_weight'] ?? '1 oz'));

            case 'gold_fingers':
            case 'goldFingers':
            case 'goldFinger':
                return self::parseBooleanOption($data['goldFingers'] ?? $data['gold_fingers'] ?? $data['goldFinger'] ?? null) ? 'Yes' : 'No';

            case 'castellated':
            case 'castellated_holes':
            case 'castellatedHoles':
                return self::parseBooleanOption($data['castellated'] ?? $data['castellatedHoles'] ?? $data['castellated_holes'] ?? null) ? 'Yes' : 'No';

            case 'edge_plating':
            case 'edgePlating':
            case 'edgeRounding':
                return self::parseBooleanOption($data['edgePlating'] ?? $data['edge_plating'] ?? $data['edgeRounding'] ?? null) ? 'Yes' : 'No';

            case 'blind_slots':
            case 'blindSlots':
                return self::parseBooleanOption($data['blindSlots'] ?? $data['blind_slots'] ?? null) ? 'Yes' : 'No';

            case 'via_covering':
            case 'viaCovering':
                return trim((string)($data['viaCovering'] ?? $data['via_covering'] ?? ''));

            case 'via_plating':
            case 'viaPlating':
            case 'viaPlatingMethod':
                return trim((string)($data['viaPlating'] ?? $data['via_plating'] ?? $data['viaPlatingMethod'] ?? ''));


            default:
                return $data[$field] ?? null;
        }
    }

    /**
     * Evaluate a condition with fuzzy normalization.
     */
    public static function evaluateCondition(string $field, mixed $actual, string $operator, mixed $expected): bool
    {
        $op = strtolower(trim($operator));

        // Special handling for Base Material FR-4 normalization
        if (in_array($field, ['base_material', 'baseMaterial', 'material'], true)) {
            $actNorm = strtolower(str_replace([' ', '-', '_'], '', (string)$actual));
            if ($op === 'equals' || $op === 'eq' || $op === '==') {
                $expNorm = strtolower(str_replace([' ', '-', '_'], '', (string)$expected));
                return $actNorm === $expNorm || ($expNorm === 'fr4' && ($actNorm === '' || $actNorm === 'fr4' || str_starts_with($actNorm, 'fr4')));
            }
            if ($op === 'in') {
                $list = is_array($expected) ? $expected : array_map('trim', explode(',', (string)$expected));
                foreach ($list as $item) {
                    $expNorm = strtolower(str_replace([' ', '-', '_'], '', (string)$item));
                    if ($actNorm === $expNorm || ($expNorm === 'fr4' && ($actNorm === '' || $actNorm === 'fr4' || str_starts_with($actNorm, 'fr4')))) {
                        return true;
                    }
                }
                return false;
            }
        }

        // Special handling for Material Type FR4 TG135 normalization
        if (in_array($field, ['material_type', 'materialType'], true)) {
            $actNorm = strtolower(str_replace([' ', '-', '_'], '', (string)$actual));
            if ($op === 'equals' || $op === 'eq' || $op === '==') {
                $expNorm = strtolower(str_replace([' ', '-', '_'], '', (string)$expected));
                return $actNorm === $expNorm || ($expNorm === 'fr4tg135' && ($actNorm === '' || $actNorm === 'fr4tg135' || $actNorm === 'tg135'));
            }
            if ($op === 'in') {
                $list = is_array($expected) ? $expected : array_map('trim', explode(',', (string)$expected));
                foreach ($list as $item) {
                    $expNorm = strtolower(str_replace([' ', '-', '_'], '', (string)$item));
                    if ($actNorm === $expNorm || ($expNorm === 'fr4tg135' && ($actNorm === '' || $actNorm === 'fr4tg135' || $actNorm === 'tg135'))) {
                        return true;
                    }
                }
                return false;
            }
        }

        // Special handling for Surface Finish HASL Leaded
        if (in_array($field, ['surface_finish', 'surfaceFinish'], true)) {
            $actLower = strtolower(trim((string)$actual));
            if ($op === 'in' || $op === 'equals' || $op === 'eq' || $op === '==') {
                $list = is_array($expected) ? $expected : array_map('trim', explode(',', (string)$expected));
                $matchesLeaded = (!$actLower || str_contains($actLower, 'hasl') || str_contains($actLower, 'leaded') || str_contains($actLower, 'with lead'))
                    && !str_contains($actLower, 'free') && !str_contains($actLower, 'enig') && !str_contains($actLower, 'osp') && !str_contains($actLower, 'gold');

                foreach ($list as $item) {
                    $itemLower = strtolower(trim((string)$item));
                    if ($actLower === $itemLower) return true;
                    if ($matchesLeaded && (str_contains($itemLower, 'hasl') || str_contains($itemLower, 'leaded'))) {
                        return true;
                    }
                }
                return false;
            }
        }

        // Numeric comparisons
        if (is_numeric($actual) && is_numeric($expected)) {
            $actNum = (float)$actual;
            $expNum = (float)$expected;

            switch ($op) {
                case 'equals':
                case 'eq':
                case '==':
                    return abs($actNum - $expNum) < 0.005;
                case 'not_equals':
                case 'neq':
                case '!=':
                    return abs($actNum - $expNum) >= 0.005;
                case 'greater_than':
                case 'gt':
                case '>':
                    return $actNum > $expNum + 0.001;
                case 'greater_than_or_equal':
                case 'gte':
                case '>=':
                    return $actNum >= $expNum - 0.005;
                case 'less_than':
                case 'lt':
                case '<':
                    return $actNum < $expNum - 0.001;
                case 'less_than_or_equal':
                case 'lte':
                case '<=':
                    return $actNum <= $expNum + 0.005;
            }
        }

        // Array inclusion operators (in, not_in)
        if ($op === 'in' || $op === 'not_in') {
            $expectedList = is_array($expected) ? $expected : array_map('trim', explode(',', (string)$expected));
            $found = false;

            $actStr = strtolower(trim((string)$actual));
            foreach ($expectedList as $item) {
                $itemStr = strtolower(trim((string)$item));
                if ($actStr === $itemStr) {
                    $found = true;
                    break;
                }
                if (is_numeric($actStr) && is_numeric($itemStr) && abs((float)$actStr - (float)$itemStr) < 0.005) {
                    $found = true;
                    break;
                }
            }

            return $op === 'in' ? $found : !$found;
        }

        // General string scalar equality
        $actStr = strtolower(trim((string)$actual));
        $expStr = strtolower(trim((string)$expected));

        switch ($op) {
            case 'equals':
            case 'eq':
            case '==':
                return $actStr === $expStr;

            case 'not_equals':
            case 'neq':
            case '!=':
                return $actStr !== $expStr;

            default:
                return false;
        }
    }

    /**
     * Format a readable description for a condition check.
     */
    protected static function formatConditionDescription(string $field, string $operator, mixed $expected, mixed $actual): string
    {
        $fieldLabels = [
            'base_material' => 'Base Material',
            'material_type' => 'Material Type',
            'layers' => 'Layer Count',
            'surface_finish' => 'Surface Finish',
            'thickness' => 'PCB Thickness',
            'min_hole' => 'Min Via Hole',
            'gold_fingers' => 'Gold Fingers',
            'castellated' => 'Castellated Holes',
            'edge_plating' => 'Edge Plating',
            'blind_slots' => 'Blind Slots',
            'via_covering' => 'Via Covering',
            'via_plating' => 'Via Plating',
        ];

        $label = $fieldLabels[$field] ?? ucwords(str_replace('_', ' ', $field));
        $expStr = is_array($expected) ? implode(', ', $expected) : (string)$expected;

        return "{$label}: '{$actual}' (Condition: {$operator} [{$expStr}])";
    }

    /**
     * Canonical hardcoded business rule fallback.
     */
    protected static function resolveCanonicalHardcoded(array $data): array
    {
        $reasons = [];

        // 1. Base Material Check: Must be FR-4
        $rawMat = trim((string)($data['baseMaterial'] ?? $data['base_material'] ?? $data['material'] ?? 'FR-4'));
        $matNorm = strtolower(str_replace([' ', '-', '_'], '', $rawMat));
        $isFr4 = ($matNorm === '' || $matNorm === 'fr4' || str_starts_with($matNorm, 'fr4'));
        if (!$isFr4) {
            $reasons[] = "Base Material: '{$rawMat}' is not eligible for In-House (FR-4 only).";
        }

        // 2. Material Type Check: Only FR4 TG135 qualifies
        $rawMt = trim((string)($data['materialType'] ?? $data['material_type'] ?? ($isFr4 ? 'FR4 TG135' : '')));
        $mtNorm = strtolower(str_replace([' ', '-', '_'], '', $rawMt));
        $isFr4Tg135 = ($mtNorm === '' || $mtNorm === 'fr4tg135' || $mtNorm === 'tg135');
        if (!$isFr4Tg135) {
            $reasons[] = "Material Type: '{$rawMt}' requires JLCPCB (In-House supports FR4 TG135 only).";
        }

        // 3. Layer Count Check: Only 1 and 2 layers qualify
        $rawLayers = (string)($data['layers'] ?? $data['layer'] ?? $data['layer_count'] ?? $data['layerCount'] ?? '2');
        $layers = (int) preg_replace('/[^0-9]/', '', $rawLayers);
        if ($layers <= 0) $layers = 2;
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

        // 8. Minimum Via Hole: Must be >= 0.30 mm
        $rawMinHole = trim((string)($data['minHole'] ?? $data['min_hole'] ?? $data['minimum_via_hole'] ?? '0.3mm'));
        if (preg_match('/(\d+(?:\.\d+)?)/', $rawMinHole, $hMatches)) {
            $holeVal = (float)$hMatches[1];
            if ($holeVal < 0.299) {
                $reasons[] = "Min Via Hole: '{$rawMinHole}' ({$holeVal}mm < 0.30mm) requires JLCPCB.";
            }
        }

        // 9. Gold Fingers: No
        if (self::parseBooleanOption($data['goldFingers'] ?? $data['gold_fingers'] ?? $data['goldFinger'] ?? null)) {
            $reasons[] = "Gold Fingers: Yes requires JLCPCB.";
        }

        // 10. Castellated Holes: No
        if (self::parseBooleanOption($data['castellated'] ?? $data['castellatedHoles'] ?? $data['castellated_holes'] ?? null)) {
            $reasons[] = "Castellated Holes: Yes requires JLCPCB.";
        }

        // 11. Edge Plating: No
        if (self::parseBooleanOption($data['edgePlating'] ?? $data['edge_plating'] ?? $data['edgeRounding'] ?? null)) {
            $reasons[] = "Edge Plating: Yes requires JLCPCB.";
        }

        // 12. Blind Slots: No
        if (self::parseBooleanOption($data['blindSlots'] ?? $data['blind_slots'] ?? null)) {
            $reasons[] = "Blind Slots: Yes requires JLCPCB.";
        }

        // 13. Via Covering: Only Tented, Untented, and Not Specified qualify
        $rawVc = trim((string)($data['viaCovering'] ?? $data['via_covering'] ?? ''));
        $vcLower = strtolower($rawVc);
        if ($vcLower !== '' && $vcLower !== 'not specified' && $vcLower !== 'tented' && $vcLower !== 'untented') {
            if (str_contains($vcLower, 'plugged')) {
                $reasons[] = "Via Covering: Plugged requires JLCPCB.";
            } elseif (str_contains($vcLower, 'epoxy')) {
                $reasons[] = "Via Covering: Epoxy Filled & Capped requires JLCPCB.";
            } elseif (str_contains($vcLower, 'copper') || (str_contains($vcLower, 'paste') && str_contains($vcLower, 'fill'))) {
                $reasons[] = "Via Covering: Copper Paste Filled & Capped requires JLCPCB.";
            } else {
                $reasons[] = "Via Covering: '{$rawVc}' requires JLCPCB.";
            }
        }

        // 14. Via Plating Method: Only Not Specified qualifies
        $rawVp = trim((string)($data['viaPlating'] ?? $data['via_plating'] ?? $data['viaPlatingMethod'] ?? ''));
        $vpLower = strtolower($rawVp);
        if ($vpLower !== '' && $vpLower !== 'not specified') {
            if (str_contains($vpLower, 'conductive') && str_contains($vpLower, 'adhesive')) {
                $reasons[] = "Via Plating: Conductive Adhesive requires JLCPCB.";
            } elseif (str_contains($vpLower, 'horizontal') || str_contains($vpLower, 'electroless')) {
                $reasons[] = "Via Plating: Horizontal Electroless Copper Plating requires JLCPCB.";
            } else {
                $reasons[] = "Via Plating: '{$rawVp}' requires JLCPCB.";
            }
        }


        $isEligible = empty($reasons);

        if ($isEligible) {
            return [
                'provider' => self::PROVIDER_IN_HOUSE,
                'is_eligible' => true,
                'rule_id' => null,
                'rule_name' => 'In-House Standard Baseline',
                'rule_slug' => 'in_house_baseline',
                'matched_conditions' => [],
                'reasons' => [],
                'quotation_source' => 'internal',
                'order_type' => 'normal',
                'series' => 'M',
            ];
        }

        return [
            'provider' => self::PROVIDER_JLCPCB,
            'is_eligible' => false,
            'rule_id' => null,
            'rule_name' => 'JLCPCB Default Fallback',
            'rule_slug' => 'jlcpcb_baseline_fallback',
            'matched_conditions' => [],
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
     * Alias for resolve($data) for naming compatibility with Phase 12.
     */
    public static function resolveQuotationProvider(array $data): array
    {
        return self::resolve($data);
    }

    /**
     * Parse truthy/falsy boolean form fields.
     */
    public static function parseBooleanOption(mixed $value): bool
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
