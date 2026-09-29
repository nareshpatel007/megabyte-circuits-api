<?php

namespace App\Services;

use App\Models\PcbPricingSetting;
use App\Models\Credential;
use Illuminate\Support\Facades\Schema;

class OrderPricingService
{
    /**
     * Get GST configuration settings from Database
     */
    public static function getGstSettings(): array
    {
        $defaultRates = [0, 5, 12, 18, 20];
        $activeRate = 18.0;

        try {
            // Check JLCPCB settings for import_gst_options
            if (Schema::hasTable('pcb_pricing_settings')) {
                $settingRow = PcbPricingSetting::where('key', 'jlcpcb_procurement_settings')->first();
                if ($settingRow && is_array($settingRow->value)) {
                    if (isset($settingRow->value['import_gst_options']) && is_array($settingRow->value['import_gst_options'])) {
                        $defaultRates = array_map('floatval', $settingRow->value['import_gst_options']);
                    }
                    if (isset($settingRow->value['sales_gst_percent']) && is_numeric($settingRow->value['sales_gst_percent'])) {
                        $activeRate = (float) $settingRow->value['sales_gst_percent'];
                    }
                }

                $gstRow = PcbPricingSetting::where('key', 'gst_percentage')->first();
                if ($gstRow && isset($gstRow->value['percentage']) && is_numeric($gstRow->value['percentage'])) {
                    $activeRate = (float) $gstRow->value['percentage'];
                }
            }

            if (Schema::hasTable('credentials')) {
                $cred = Credential::where('key', 'GST_PERCENTAGE')->first();
                if ($cred && !empty($cred->decrypted_value) && is_numeric($cred->decrypted_value)) {
                    $activeRate = (float) $cred->decrypted_value;
                }
            }
        } catch (\Throwable $e) {
            // Fallback to default options if error occurs
        }

        // Ensure active rate is in rates array
        if (!in_array($activeRate, $defaultRates)) {
            $defaultRates[] = $activeRate;
            sort($defaultRates);
        }

        return [
            'rates' => array_values(array_unique($defaultRates)),
            'active_rate' => $activeRate
        ];
    }

    /**
     * Calculate authoritative PCB Order pricing breakdown
     */
    public static function calculateOrderPricing(array $params): array
    {
        $pricingMethod = strtolower(trim((string)($params['pricing_method'] ?? 'auto')));
        $qty = max(1, (int)($params['launch_qty'] ?? $params['order_qty'] ?? $params['quantity'] ?? 1));
        
        $length = floatval($params['dimensions_length'] ?? $params['length'] ?? 100);
        $width = floatval($params['dimensions_width'] ?? $params['width'] ?? 100);
        if ($length <= 0) $length = 100;
        if ($width <= 0) $width = 100;

        $pcbRate = floatval($params['pcb_rate'] ?? 0);
        $pricePerSqm = floatval($params['price_per_sqm'] ?? 0);
        $autoValue = floatval($params['auto_calculated_value'] ?? $params['order_value'] ?? 0);

        // Area calculations (Length mm * Width mm / 1,000,000 = SQM per board)
        $areaPerBoardSqm = ($length * $width) / 1000000.0;
        $totalAreaSqm = $areaPerBoardSqm * $qty;

        $basePcbAmount = 0.0;

        if ($pricingMethod === 'pcb_rate') {
            $basePcbAmount = $pcbRate * $qty;
        } elseif ($pricingMethod === 'price_per_sqm') {
            $basePcbAmount = $pricePerSqm * $totalAreaSqm;
        } else {
            $pricingMethod = 'auto';
            $basePcbAmount = $autoValue;
        }

        $subtotal = round(max(0, $basePcbAmount), 2);

        // GST calculation
        $gstSettings = static::getGstSettings();
        $requestedGstRate = isset($params['gst_rate']) && is_numeric($params['gst_rate'])
            ? (float)$params['gst_rate']
            : $gstSettings['active_rate'];

        // Ensure requested GST rate is valid
        if (!in_array($requestedGstRate, $gstSettings['rates'])) {
            $requestedGstRate = $gstSettings['active_rate'];
        }

        $gstAmount = round($subtotal * ($requestedGstRate / 100.0), 2);
        $totalAmount = round($subtotal + $gstAmount, 2);
        $unitPrice = $qty > 0 ? round($subtotal / $qty, 2) : 0.0;

        return [
            'pricing_method' => $pricingMethod,
            'pcb_rate' => $pcbRate,
            'price_per_sqm' => $pricePerSqm,
            'area_per_board_sqm' => round($areaPerBoardSqm, 6),
            'total_area_sqm' => round($totalAreaSqm, 6),
            'subtotal' => $subtotal,
            'gst_rate' => $requestedGstRate,
            'gst_amount' => $gstAmount,
            'total_amount' => $totalAmount,
            'unit_price' => $unitPrice,
            'qty' => $qty,
        ];
    }
}
