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
        $autoValue = floatval($params['subtotal'] ?? $params['auto_calculated_value'] ?? $params['order_value'] ?? 0);

        // Check for manual price override (takes absolute top precedence)
        $manualPrice = null;
        if (isset($params['manual_price']) && is_numeric($params['manual_price']) && floatval($params['manual_price']) >= 0) {
            $manualPrice = floatval($params['manual_price']);
        } elseif (isset($params['manual_pcb_price']) && is_numeric($params['manual_pcb_price']) && floatval($params['manual_pcb_price']) >= 0) {
            $manualPrice = floatval($params['manual_pcb_price']);
        }

        // Unit conversion (inches to mm: * 25.4)
        $unit = strtolower(trim((string)($params['dimension_unit'] ?? $params['unit'] ?? 'mm')));
        $unitMult = ($unit === 'inch' || $unit === 'inches' || $unit === 'in') ? 25.4 : 1.0;
        $lengthMm = $length * $unitMult;
        $widthMm = $width * $unitMult;

        // Area calculations (Length mm * Width mm / 1,000,000 = SQM per board)
        $areaPerBoardSqm = ($lengthMm * $widthMm) / 1000000.0;
        $totalAreaSqm = $areaPerBoardSqm * $qty;

        $basePcbAmount = 0.0;

        if ($manualPrice !== null) {
            $basePcbAmount = $manualPrice;
            $pricingMethod = 'manual';
        } elseif ($pricingMethod === 'pcb_rate') {
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
            'manual_price' => $manualPrice,
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

    /**
     * Determine authoritatively whether a PCB configuration requires JLCPCB manufacturing / pricing.
     *
     * Canonical Business Rules:
     * 1. Stencils and Parts are strictly Local (never JLCPCB).
     * 2. Standard FR-4 with 1 or 2 layers ALWAYS uses LOCAL quotation & manufacturing.
     * 3. More than 2 layers (> 2: 4, 6, 8, 10, etc.) MUST use JLCPCB.
     * 4. Non-FR-4 base materials (Flex, Rogers, PTFE Teflon, Aluminum, etc.) use JLCPCB.
     * 5. Uploaded Gerber files (fileKey / jlcpcb_file_key) NEVER force JLCPCB for 1 or 2 layer FR-4.
     *
     * @param array $data PCB specifications or request payload
     * @return bool
     */
    public static function isJlcpcbRequired(array $data): bool
    {
        $productType = strtolower(trim((string)($data['productType'] ?? $data['product_type'] ?? 'pcb')));
        if ($productType === 'part' || $productType === 'stencil') {
            return false;
        }

        // Parse numeric layer count
        $rawLayers = (string)($data['layers'] ?? $data['layer'] ?? '2');
        $layers = (int) preg_replace('/[^0-9]/', '', $rawLayers);
        if ($layers <= 0) {
            $layers = 2;
        }

        // Base material check
        $mat = strtolower(trim((string)($data['baseMaterial'] ?? $data['base_material'] ?? $data['material'] ?? 'FR-4')));
        $isFr4 = empty($mat) || $mat === 'fr-4' || $mat === 'fr4' || $mat === 'fr_4' ||
                 str_contains($mat, 'fr-4') || str_contains($mat, 'fr4') || str_contains($mat, 'fr_4') ||
                 str_contains($mat, 'standard') || str_contains($mat, 'tg135') || str_contains($mat, 'tg140') ||
                 str_contains($mat, 'tg150') || str_contains($mat, 'tg170');

        // Canonical Business Rule:
        // FR-4 for 1 and 2 layers always calculates from the LOCAL PRICING METHOD.
        if ($isFr4 && $layers <= 2) {
            return false;
        }

        // 1. Multilayer PCB (> 2 layers: 4, 6, 8, 10...) must use JLCPCB
        if ($layers > 2) {
            return true;
        }

        // 2. Base Material other than FR-4 must use JLCPCB (Flex, Rogers, PTFE, Aluminum, etc.)
        if (!$isFr4) {
            return true;
        }

        return false;
    }

    /**
     * Authoritatively resolve quotation source, order type, and series for an order or cart item.
     * The backend derives the source strictly from the PCB configuration, never blindly trusting
     * stale frontend flags.
     *
     * @param array $data PCB specifications or request payload
     * @return array ['quotation_source' => 'internal'|'jlcpcb', 'order_type' => 'normal'|'jlcpcb', 'series' => 'M'|'JL']
     */
    public static function resolveOrderSource(array $data): array
    {
        $requiresJlcpcb = self::isJlcpcbRequired($data);

        if ($requiresJlcpcb) {
            return [
                'quotation_source' => 'jlcpcb',
                'order_type' => 'jlcpcb',
                'series' => 'JL',
            ];
        }

        return [
            'quotation_source' => 'internal',
            'order_type' => 'normal',
            'series' => 'M',
        ];
    }
}

