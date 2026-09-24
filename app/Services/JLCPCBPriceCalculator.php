<?php

namespace App\Services;

class JLCPCBPriceCalculator
{
    /**
     * Get default settings for JLCPCB procurement calculation
     */
    public static function getDefaultSettings(): array
    {
        return [
            'international_shipping_usd' => 25.00,
            'usd_to_inr_rate' => 100.00,
            'customs_duty_percent' => 30.00,
            'customs_other_charges' => 0.00,
            'sws_charges' => 0.00,
            'import_gst_percent' => 18.00,
            'import_gst_options' => [0, 5, 12, 18, 20],
            'customs_clearing' => 0.00,
            'bank_forex_payment_charges' => 0.00,
            'domestic_freight' => 1000.00,
            'other_buy_expenses' => 0.00,
            'margin_markup_percent' => 20.00,
            'sales_gst_percent' => 18.00,
        ];
    }

    /**
     * Load stored settings from DB (pcb_pricing_settings or credentials) falling back to defaults
     */
    public static function getStoredSettings(): array
    {
        $defaults = static::getDefaultSettings();

        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('pcb_pricing_settings')) {
                $settingRow = \App\Models\PcbPricingSetting::where('key', 'jlcpcb_procurement_settings')->first();
                if ($settingRow && is_array($settingRow->value)) {
                    $dbValues = $settingRow->value;
                    foreach ($defaults as $key => $defaultVal) {
                        if (array_key_exists($key, $dbValues)) {
                            $defaults[$key] = $dbValues[$key];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fallback to defaults on DB error
        }

        return $defaults;
    }

    /**
     * Save settings to DB
     */
    public static function saveSettings(array $inputSettings): array
    {
        $currentSettings = static::getStoredSettings();
        $updatedSettings = array_merge($currentSettings, $inputSettings);

        // Ensure numeric types
        $numericFields = [
            'international_shipping_usd',
            'usd_to_inr_rate',
            'customs_duty_percent',
            'customs_other_charges',
            'sws_charges',
            'import_gst_percent',
            'customs_clearing',
            'bank_forex_payment_charges',
            'domestic_freight',
            'other_buy_expenses',
            'margin_markup_percent',
            'sales_gst_percent'
        ];

        foreach ($numericFields as $field) {
            if (isset($updatedSettings[$field])) {
                $updatedSettings[$field] = round((float)$updatedSettings[$field], 4);
            }
        }

        // Handle GST options list
        if (isset($inputSettings['import_gst_options']) && is_array($inputSettings['import_gst_options'])) {
            $options = array_map('floatval', $inputSettings['import_gst_options']);
            sort($options);
            $updatedSettings['import_gst_options'] = array_values(array_unique($options));
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('pcb_pricing_settings')) {
            \App\Models\PcbPricingSetting::updateOrCreate(
                ['key' => 'jlcpcb_procurement_settings'],
                [
                    'value' => $updatedSettings,
                    'description' => 'JLCPCB Procurement & Pricing Settings'
                ]
            );

            // Also keep individual key fallbacks synchronized
            \App\Models\PcbPricingSetting::updateOrCreate(
                ['key' => 'jlcpcb_margin'],
                ['value' => ['margin' => (float)$updatedSettings['margin_markup_percent']], 'description' => 'JLCPCB Admin Margin Percentage']
            );
            \App\Models\PcbPricingSetting::updateOrCreate(
                ['key' => 'gst_percentage'],
                ['value' => ['percentage' => (float)$updatedSettings['sales_gst_percent']], 'description' => 'GST Percentage for PCB calculations']
            );
        }

        return $updatedSettings;
    }

    /**
     * Calculate full procurement & pricing breakdown based on JLCPCB API PCB purchase price
     */
    public function calculate(
        float $pcbPurchasePriceUsd,
        ?array $overrideSettings = null,
        int $quantity = 1,
        ?float $apiShippingUsd = null
    ): array {
        $settings = array_merge(static::getStoredSettings(), $overrideSettings ?? []);

        // Determine International Shipping (USD):
        // If international_shipping_usd is set and > 0, use it as fixed delivery charges.
        // If not set or <= 0, calculate price from actual JLCPCB API shipping cost ($apiShippingUsd).
        $configuredShipUsd = isset($settings['international_shipping_usd']) ? (float)$settings['international_shipping_usd'] : 0.0;

        if ($configuredShipUsd > 0) {
            $shipUsd = $configuredShipUsd;
            $shippingSource = 'configured_setting';
        } else {
            $shipUsd = ($apiShippingUsd !== null && $apiShippingUsd >= 0) ? (float)$apiShippingUsd : 0.0;
            $shippingSource = 'jlcpcb_api';
        }

        $exchangeRate = (float)($settings['usd_to_inr_rate'] ?? 100.0);
        $dutyPct = (float)($settings['customs_duty_percent'] ?? 30.0);
        $otherDuty = (float)($settings['customs_other_charges'] ?? 0.0);
        $sws = (float)($settings['sws_charges'] ?? 0.0);
        $importGstPct = (float)($settings['import_gst_percent'] ?? 18.0);
        $clearing = (float)($settings['customs_clearing'] ?? 0.0);
        $bankCharges = (float)($settings['bank_forex_payment_charges'] ?? 0.0);
        $domesticFreight = (float)($settings['domestic_freight'] ?? 1000.0);
        $otherBuyExpenses = (float)($settings['other_buy_expenses'] ?? 0.0);
        $marginPct = (float)($settings['margin_markup_percent'] ?? 20.0);
        $salesGstPct = (float)($settings['sales_gst_percent'] ?? 18.0);

        // 1. Goods + International Shipping (USD)
        $totalUsd = round($pcbPurchasePriceUsd + $shipUsd, 4);

        // 2. Import Purchase Value (INR)
        $importPurchaseValue = round($totalUsd * $exchangeRate, 2);

        // 3. Customs Duty (INR)
        $customsDuty = round($importPurchaseValue * ($dutyPct / 100.0), 2);

        // 4. Import GST / IGST Base (goods + duty + sws + other duty)
        $gstBase = round($importPurchaseValue + $customsDuty + $sws + $otherDuty, 2);
        $importGst = round($gstBase * ($importGstPct / 100.0), 2);

        // 5. Local / Clearing Expenses
        $localExpenses = round($clearing + $bankCharges + $domesticFreight + $otherBuyExpenses, 2);

        // 6. BUY COST — BEFORE GST
        $buyTotalExGst = round($importPurchaseValue + $customsDuty + $otherDuty + $sws + $localExpenses, 2);

        // 7. TOTAL BUY COST (Including Import GST)
        $totalBuyCost = round($buyTotalExGst + $importGst, 2);

        // 8. Quantity Per-Unit Calculations
        $qty = max(1, $quantity);
        $buyPerUnitExGst = round($buyTotalExGst / $qty, 4);
        $buyPerUnitIncGst = round($totalBuyCost / $qty, 4);

        // 9. Margin / Profit Amount on Total Buy Cost
        $marginAmount = round($totalBuyCost * ($marginPct / 100.0), 2);

        // 10. Selling Price Before Sales GST
        $sellingPriceBeforeGst = round($totalBuyCost + $marginAmount, 2);

        // 11. Sales GST
        $salesGstAmount = round($sellingPriceBeforeGst * ($salesGstPct / 100.0), 2);

        // 12. Final Customer Price
        $finalCustomerPrice = round($sellingPriceBeforeGst + $salesGstAmount, 2);

        // 13. Per-Unit Customer Pricing
        $sellPerUnitExGst = round($sellingPriceBeforeGst / $qty, 4);
        $sellPerUnitIncGst = round($finalCustomerPrice / $qty, 4);
        $profitPerUnit = round($marginAmount / $qty, 4);

        return [
            'pcb_purchase_price_usd' => round($pcbPurchasePriceUsd, 2),
            'international_shipping_usd' => round($shipUsd, 2),
            'shipping_source' => $shippingSource,
            'total_usd' => round($totalUsd, 2),

            'usd_to_inr_rate' => round($exchangeRate, 2),
            'import_purchase_value' => round($importPurchaseValue, 2),

            'customs_duty_percent' => round($dutyPct, 2),
            'customs_duty' => round($customsDuty, 2),
            'customs_other_charges' => round($otherDuty, 2),
            'sws_charges' => round($sws, 2),

            'import_gst_percent' => round($importGstPct, 2),
            'import_gst_base' => round($gstBase, 2),
            'import_gst' => round($importGst, 2),

            'customs_clearing' => round($clearing, 2),
            'bank_forex_payment_charges' => round($bankCharges, 2),
            'domestic_freight' => round($domesticFreight, 2),
            'other_buy_expenses' => round($otherBuyExpenses, 2),
            'local_expenses' => round($localExpenses, 2),

            'buy_total_ex_gst' => round($buyTotalExGst, 2),
            'total_buy_cost' => round($totalBuyCost, 2),

            'quantity' => $qty,
            'buy_per_unit_ex_gst' => round($buyPerUnitExGst, 3),
            'buy_per_unit_inc_gst' => round($buyPerUnitIncGst, 3),

            'margin_markup_percent' => round($marginPct, 2),
            'margin_amount' => round($marginAmount, 2),

            'selling_price_before_gst' => round($sellingPriceBeforeGst, 2),

            'sales_gst_percent' => round($salesGstPct, 2),
            'sales_gst_amount' => round($salesGstAmount, 2),

            'final_customer_price' => round($finalCustomerPrice, 2),

            'sell_per_unit_ex_gst' => round($sellPerUnitExGst, 3),
            'sell_per_unit_inc_gst' => round($sellPerUnitIncGst, 3),
            'profit_per_unit' => round($profitPerUnit, 3)
        ];
    }
}
