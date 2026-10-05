<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PcbProviderRule;
use App\Models\PcbProviderRuleCondition;
use App\Services\ManufacturingProviderResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PcbProviderRulesController extends Controller
{
    /**
     * GET /api/admin/provider-rules
     * List all provider routing rules with conditions.
     */
    public function index()
    {
        $rules = PcbProviderRule::with(['conditions' => function ($q) {
            $q->orderBy('sort_order', 'asc');
        }])
        ->ordered()
        ->get();

        return response()->json([
            'success' => true,
            'data' => $rules,
            'meta' => [
                'total_rules' => $rules->count(),
                'active_rules' => $rules->where('is_active', true)->count(),
            ]
        ]);
    }

    /**
     * GET /api/admin/provider-rules/fields
     * Metadata describing available fields, operators, and options for condition builder.
     */
    public function getFields()
    {
        $fields = [
            [
                'field' => 'base_material',
                'label' => 'Base Material',
                'type' => 'select',
                'options' => ['FR-4', 'Flex', 'Rogers', 'PTFE Teflon'],
                'supported_operators' => ['equals', 'not_equals', 'in', 'not_in'],
                'default_operator' => 'in',
                'default_value' => ['FR-4'],
                'tooltip' => 'Base PCB material category',
            ],
            [
                'field' => 'material_type',
                'label' => 'Material Type',
                'type' => 'select',
                'options' => [
                    'FR4 TG135',
                    'KB6164 - TG135',
                    'Nan Ya NP-140F',
                    'S1141 TG140',
                    'S1000H TG155',
                    'RO4350B(Dk=3.48,Df=0.0037)',
                    'ZYF300CA-C(Dk=2.94,Df=0.0016)',
                    'Polyimide (PI)'
                ],
                'supported_operators' => ['equals', 'not_equals', 'in', 'not_in'],
                'default_operator' => 'in',
                'default_value' => ['FR4 TG135'],
                'tooltip' => 'Laminate manufacturer brand and Tg specification',
            ],
            [
                'field' => 'layers',
                'label' => 'Layers',
                'type' => 'number_select',
                'options' => [1, 2, 4, 6, 8, 10, 12, 14, 16],
                'supported_operators' => ['equals', 'not_equals', 'in', 'not_in', 'less_than_or_equal', 'greater_than_or_equal'],
                'default_operator' => 'in',
                'default_value' => [1, 2],
                'tooltip' => 'Total copper layer count',
            ],
            [
                'field' => 'surface_finish',
                'label' => 'Surface Finish',
                'type' => 'select',
                'options' => ['HASL(Leaded)', 'LeadFree HASL', 'ENIG', 'OSP'],
                'supported_operators' => ['equals', 'not_equals', 'in', 'not_in'],
                'default_operator' => 'in',
                'default_value' => ['HASL(Leaded)'],
                'tooltip' => 'Exposed copper surface finishing',
            ],
            [
                'field' => 'thickness',
                'label' => 'PCB Thickness (mm)',
                'type' => 'numeric',
                'options' => [0.6, 0.8, 1.0, 1.2, 1.6, 2.0],
                'supported_operators' => ['equals', 'not_equals', 'in', 'not_in', 'less_than_or_equal', 'greater_than_or_equal'],
                'default_operator' => 'not_equals',
                'default_value' => 0.6,
                'tooltip' => 'Finished board thickness in millimeters',
            ],
            [
                'field' => 'min_hole',
                'label' => 'Min Via Hole Size (mm)',
                'type' => 'numeric',
                'options' => [0.30, 0.25, 0.20, 0.15],
                'supported_operators' => ['greater_than_or_equal', 'greater_than', 'equals', 'less_than_or_equal'],
                'default_operator' => 'greater_than_or_equal',
                'default_value' => 0.30,
                'tooltip' => 'Minimum via drill diameter in millimeters',
            ],
            [
                'field' => 'gold_fingers',
                'label' => 'Gold Fingers',
                'type' => 'boolean',
                'options' => ['No', 'Yes'],
                'supported_operators' => ['equals', 'not_equals'],
                'default_operator' => 'equals',
                'default_value' => 'No',
                'tooltip' => 'Edge connector gold electroplating',
            ],
            [
                'field' => 'castellated',
                'label' => 'Castellated Holes',
                'type' => 'boolean',
                'options' => ['No', 'Yes'],
                'supported_operators' => ['equals', 'not_equals'],
                'default_operator' => 'equals',
                'default_value' => 'No',
                'tooltip' => 'Plated half-holes on board edges',
            ],
            [
                'field' => 'edge_plating',
                'label' => 'Edge Plating',
                'type' => 'boolean',
                'options' => ['No', 'Yes'],
                'supported_operators' => ['equals', 'not_equals'],
                'default_operator' => 'equals',
                'default_value' => 'No',
                'tooltip' => 'Copper plated board outline edge',
            ],
            [
                'field' => 'blind_slots',
                'label' => 'Blind Slots',
                'type' => 'boolean',
                'options' => ['No', 'Yes'],
                'supported_operators' => ['equals', 'not_equals'],
                'default_operator' => 'equals',
                'default_value' => 'No',
                'tooltip' => 'Controlled depth blind slots / cavities',
            ],
            [
                'field' => 'via_covering',
                'label' => 'Via Covering',
                'type' => 'select',
                'options' => ['Tented', 'Untented', 'Plugged', 'Epoxy Filled & Capped', 'Copper paste Filled & Capped', 'Not Specified'],
                'supported_operators' => ['equals', 'not_equals', 'in', 'not_in'],
                'default_operator' => 'in',
                'default_value' => ['Tented', 'Untented', 'Not Specified'],
                'tooltip' => 'Via hole solder mask tenting or filling method',
            ],
            [
                'field' => 'via_plating',
                'label' => 'Via Plating Method',
                'type' => 'select',
                'options' => ['Not Specified', 'Conductive Adhesive', 'Horizontal Electroless Copper Plating'],
                'supported_operators' => ['equals', 'not_equals', 'in', 'not_in'],
                'default_operator' => 'in',
                'default_value' => ['Not Specified'],
                'tooltip' => 'Special conductive via plating technology',
            ],
            [
                'field' => 'mark_on_pcb',
                'label' => 'Mark on PCB',
                'type' => 'select',
                'options' => ['none', 'Remove Mark', 'Not Specified'],
                'supported_operators' => ['equals', 'not_equals', 'in', 'not_in'],
                'default_operator' => 'in',
                'default_value' => ['none', 'Not Specified', 'no'],
                'tooltip' => 'Manufacturer serial mark or barcode placement',
            ],
            [
                'field' => 'elec_test',
                'label' => 'Electrical Test',
                'type' => 'select',
                'options' => ['none', 'not tested', 'Flying Probe Fully Test'],
                'supported_operators' => ['equals', 'not_equals', 'in', 'not_in'],
                'default_operator' => 'in',
                'default_value' => ['none', 'not tested'],
                'tooltip' => 'Testing methodology specification',
            ],
        ];

        $operators = [
            ['value' => 'equals', 'label' => 'equals (==)'],
            ['value' => 'not_equals', 'label' => 'not equals (!=)'],
            ['value' => 'in', 'label' => 'in list [A, B, ...]'],
            ['value' => 'not_in', 'label' => 'not in list'],
            ['value' => 'greater_than_or_equal', 'label' => 'greater than or equal (>=)'],
            ['value' => 'greater_than', 'label' => 'greater than (>)'],
            ['value' => 'less_than_or_equal', 'label' => 'less than or equal (<=)'],
            ['value' => 'less_than', 'label' => 'less than (<)'],
        ];

        return response()->json([
            'success' => true,
            'fields' => $fields,
            'operators' => $operators,
            'providers' => [
                ['value' => 'IN_HOUSE', 'label' => 'IN-HOUSE (Local Manufacturing)'],
                ['value' => 'JLCPCB', 'label' => 'JLCPCB (External Partner)'],
            ],
            'match_types' => [
                ['value' => 'ALL', 'label' => 'Match ALL Conditions (AND)'],
                ['value' => 'ANY', 'label' => 'Match ANY Condition (OR)'],
            ],
        ]);
    }

    /**
     * POST /api/admin/provider-rules
     * Create a new provider routing rule.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'slug' => 'nullable|string|max:100|unique:pcb_provider_rules,slug',
            'provider' => 'required|string|in:IN_HOUSE,JLCPCB',
            'priority' => 'required|integer|min:1|max:999',
            'match_type' => 'required|string|in:ALL,ANY',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'conditions' => 'required|array',
            'conditions.*.field' => 'required|string|max:100',
            'conditions.*.operator' => 'required|string|max:50',
            'conditions.*.value' => 'required',
        ]);

        $slug = !empty($validated['slug'])
            ? Str::slug($validated['slug'], '_')
            : Str::slug($validated['name'], '_');

        // Ensure unique slug
        $baseSlug = $slug;
        $counter = 1;
        while (PcbProviderRule::where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}_{$counter}";
            $counter++;
        }

        $rule = DB::transaction(function () use ($validated, $slug) {
            $rule = PcbProviderRule::create([
                'name' => $validated['name'],
                'slug' => $slug,
                'provider' => $validated['provider'],
                'priority' => $validated['priority'],
                'match_type' => $validated['match_type'],
                'description' => $validated['description'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'sort_order' => $validated['sort_order'] ?? 0,
            ]);

            foreach ($validated['conditions'] as $idx => $cond) {
                PcbProviderRuleCondition::create([
                    'rule_id' => $rule->id,
                    'field' => $cond['field'],
                    'operator' => $cond['operator'],
                    'value' => $cond['value'],
                    'sort_order' => $cond['sort_order'] ?? ($idx + 1),
                ]);
            }

            return $rule;
        });

        ManufacturingProviderResolver::clearCache();

        return response()->json([
            'success' => true,
            'message' => "Provider routing rule '{$rule->name}' created successfully.",
            'data' => $rule->load('conditions'),
        ], 201);
    }

    /**
     * GET /api/admin/provider-rules/{id}
     */
    public function show($id)
    {
        $rule = PcbProviderRule::with(['conditions' => function ($q) {
            $q->orderBy('sort_order', 'asc');
        }])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $rule,
        ]);
    }

    /**
     * PUT /api/admin/provider-rules/{id}
     * Update rule and replace its conditions.
     */
    public function update(Request $request, $id)
    {
        $rule = PcbProviderRule::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'slug' => "nullable|string|max:100|unique:pcb_provider_rules,slug,{$id}",
            'provider' => 'required|string|in:IN_HOUSE,JLCPCB',
            'priority' => 'required|integer|min:1|max:999',
            'match_type' => 'required|string|in:ALL,ANY',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'conditions' => 'required|array',
            'conditions.*.field' => 'required|string|max:100',
            'conditions.*.operator' => 'required|string|max:50',
            'conditions.*.value' => 'required',
        ]);

        $slug = !empty($validated['slug'])
            ? Str::slug($validated['slug'], '_')
            : $rule->slug;

        DB::transaction(function () use ($rule, $validated, $slug) {
            $rule->update([
                'name' => $validated['name'],
                'slug' => $slug,
                'provider' => $validated['provider'],
                'priority' => $validated['priority'],
                'match_type' => $validated['match_type'],
                'description' => $validated['description'] ?? null,
                'is_active' => $validated['is_active'] ?? $rule->is_active,
                'sort_order' => $validated['sort_order'] ?? $rule->sort_order,
            ]);

            // Replace conditions
            $rule->conditions()->delete();

            foreach ($validated['conditions'] as $idx => $cond) {
                PcbProviderRuleCondition::create([
                    'rule_id' => $rule->id,
                    'field' => $cond['field'],
                    'operator' => $cond['operator'],
                    'value' => $cond['value'],
                    'sort_order' => $cond['sort_order'] ?? ($idx + 1),
                ]);
            }
        });

        ManufacturingProviderResolver::clearCache();

        return response()->json([
            'success' => true,
            'message' => "Provider routing rule '{$rule->name}' updated successfully.",
            'data' => $rule->fresh('conditions'),
        ]);
    }

    /**
     * DELETE /api/admin/provider-rules/{id}
     */
    public function destroy($id)
    {
        $rule = PcbProviderRule::findOrFail($id);
        $name = $rule->name;

        $rule->delete();
        ManufacturingProviderResolver::clearCache();

        return response()->json([
            'success' => true,
            'message' => "Rule '{$name}' deleted successfully.",
        ]);
    }

    /**
     * PUT /api/admin/provider-rules/{id}/toggle
     */
    public function toggleStatus($id)
    {
        $rule = PcbProviderRule::findOrFail($id);
        $rule->is_active = !$rule->is_active;
        $rule->save();

        ManufacturingProviderResolver::clearCache();

        $statusStr = $rule->is_active ? 'activated' : 'deactivated';
        return response()->json([
            'success' => true,
            'message' => "Rule '{$rule->name}' has been {$statusStr}.",
            'data' => $rule,
        ]);
    }

    /**
     * POST /api/admin/provider-rules/preview
     * Test / Preview tool (Phase 28): Admin tests a configuration against rules.
     */
    public function preview(Request $request)
    {
        $specs = $request->all();
        $result = ManufacturingProviderResolver::resolve($specs);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * POST /api/admin/provider-rules/reset
     * Reset rules back to canonical seeded default.
     */
    public function resetToDefault()
    {
        \Artisan::call('db:seed', ['--class' => 'PcbProviderRulesSeeder', '--force' => true]);
        ManufacturingProviderResolver::clearCache();

        return response()->json([
            'success' => true,
            'message' => 'Provider routing rules successfully reset to canonical default.',
            'data' => PcbProviderRule::with('conditions')->ordered()->get(),
        ]);
    }
}
