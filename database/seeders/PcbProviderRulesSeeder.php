<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PcbProviderRule;
use App\Models\PcbProviderRuleCondition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class PcbProviderRulesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Seed the canonical In-House routing rule idempotently.
     */
    public function run(): void
    {
        DB::transaction(function () {
            $rule = PcbProviderRule::updateOrCreate(
                ['slug' => 'in_house_standard_pcb'],
                [
                    'name' => 'In-House Standard 1-2 Layer PCB',
                    'provider' => 'IN_HOUSE',
                    'priority' => 10,
                    'match_type' => 'ALL',
                    'description' => 'Canonical business rules for In-House manufacturing qualification: 1-2L FR-4, FR4 TG135, HASL Leaded, >=0.30mm via, no special high-spec features.',
                    'is_active' => true,
                    'sort_order' => 1,
                ]
            );

            // Re-seed conditions cleanly
            PcbProviderRuleCondition::where('rule_id', $rule->id)->delete();

            $conditions = [
                [
                    'field' => 'base_material',
                    'operator' => 'in',
                    'value' => ['FR-4', 'FR4'],
                    'sort_order' => 1,
                ],
                [
                    'field' => 'material_type',
                    'operator' => 'in',
                    'value' => ['FR4 TG135', 'FR4-TG135'],
                    'sort_order' => 2,
                ],
                [
                    'field' => 'layers',
                    'operator' => 'in',
                    'value' => [1, 2, '1', '2'],
                    'sort_order' => 3,
                ],
                [
                    'field' => 'surface_finish',
                    'operator' => 'in',
                    'value' => ['HASL(Leaded)', 'HASL(with lead)', 'HASL'],
                    'sort_order' => 4,
                ],
                [
                    'field' => 'thickness',
                    'operator' => 'not_equals',
                    'value' => 0.6,
                    'sort_order' => 5,
                ],
                [
                    'field' => 'min_hole',
                    'operator' => 'greater_than_or_equal',
                    'value' => 0.30,
                    'sort_order' => 6,
                ],
                [
                    'field' => 'gold_fingers',
                    'operator' => 'equals',
                    'value' => 'No',
                    'sort_order' => 7,
                ],
                [
                    'field' => 'castellated',
                    'operator' => 'equals',
                    'value' => 'No',
                    'sort_order' => 8,
                ],
                [
                    'field' => 'edge_plating',
                    'operator' => 'equals',
                    'value' => 'No',
                    'sort_order' => 9,
                ],
                [
                    'field' => 'blind_slots',
                    'operator' => 'equals',
                    'value' => 'No',
                    'sort_order' => 10,
                ],
                [
                    'field' => 'via_covering',
                    'operator' => 'in',
                    'value' => ['Tented', 'Untented', 'Not Specified', ''],
                    'sort_order' => 11,
                ],
                [
                    'field' => 'via_plating',
                    'operator' => 'in',
                    'value' => ['Not Specified', ''],
                    'sort_order' => 12,
                ],
            ];

            foreach ($conditions as $cond) {
                PcbProviderRuleCondition::create([
                    'rule_id' => $rule->id,
                    'field' => $cond['field'],
                    'operator' => $cond['operator'],
                    'value' => $cond['value'],
                    'sort_order' => $cond['sort_order'],
                ]);
            }
        });

        Cache::forget('pcb_provider_rules_active');
    }
}
