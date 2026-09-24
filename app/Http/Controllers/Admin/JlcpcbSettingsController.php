<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\JLCPCBPriceCalculator;
use Throwable;

class JlcpcbSettingsController extends Controller
{
    /**
     * Get JLCPCB management settings
     * GET /api/admin/jlcpcb-settings
     */
    public function index()
    {
        try {
            $settings = JLCPCBPriceCalculator::getStoredSettings();

            return response()->json([
                'success' => true,
                'data' => $settings
            ]);
        } catch (Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load JLCPCB settings: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Update JLCPCB management settings
     * POST /api/admin/jlcpcb-settings
     */
    public function update(Request $request)
    {
        try {
            $validated = $request->validate([
                'international_shipping_usd' => 'nullable|numeric|min:0',
                'usd_to_inr_rate' => 'nullable|numeric|min:0.01',
                'customs_duty_percent' => 'nullable|numeric|min:0',
                'customs_other_charges' => 'nullable|numeric|min:0',
                'sws_charges' => 'nullable|numeric|min:0',
                'import_gst_percent' => 'nullable|numeric|min:0',
                'import_gst_options' => 'nullable|array',
                'customs_clearing' => 'nullable|numeric|min:0',
                'bank_forex_payment_charges' => 'nullable|numeric|min:0',
                'domestic_freight' => 'nullable|numeric|min:0',
                'other_buy_expenses' => 'nullable|numeric|min:0',
                'margin_markup_percent' => 'nullable|numeric|min:0',
                'sales_gst_percent' => 'nullable|numeric|min:0',
            ]);

            $saved = JLCPCBPriceCalculator::saveSettings($validated);

            return response()->json([
                'success' => true,
                'message' => 'JLCPCB Procurement Settings updated successfully',
                'data' => $saved
            ]);
        } catch (Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update JLCPCB settings: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Add a new GST percentage option to available options list
     * POST /api/admin/jlcpcb-settings/gst-options
     */
    public function addGstOption(Request $request)
    {
        try {
            $request->validate([
                'rate' => 'required|numeric|min:0|max:100'
            ]);

            $newRate = round((float)$request->input('rate'), 2);
            $currentSettings = JLCPCBPriceCalculator::getStoredSettings();
            $options = $currentSettings['import_gst_options'] ?? [0, 5, 12, 18, 20];

            if (!in_array($newRate, $options)) {
                $options[] = $newRate;
                sort($options);
            }

            $saved = JLCPCBPriceCalculator::saveSettings([
                'import_gst_options' => $options
            ]);

            return response()->json([
                'success' => true,
                'message' => "GST rate {$newRate}% added successfully",
                'data' => $saved
            ]);
        } catch (Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add GST option: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Preview calculation breakdown using custom sample inputs
     * POST /api/admin/jlcpcb-settings/preview
     */
    public function calculatePreview(Request $request)
    {
        try {
            $pcbUsd = (float)$request->input('pcb_purchase_price_usd', 75.0);
            $qty = (int)$request->input('quantity', 1);
            $overrideSettings = $request->input('settings', []);

            $calculator = new JLCPCBPriceCalculator();
            $breakdown = $calculator->calculate($pcbUsd, $overrideSettings, $qty);

            return response()->json([
                'success' => true,
                'data' => $breakdown
            ]);
        } catch (Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Calculation error: ' . $th->getMessage()
            ], 500);
        }
    }
}
