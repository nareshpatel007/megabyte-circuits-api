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

        // Delivery charge calculation
        $deliveryCharge = 0.0;
        if (isset($params['delivery_charge']) && is_numeric($params['delivery_charge'])) {
            $deliveryCharge = max(0.0, floatval($params['delivery_charge']));
        } elseif (isset($params['shipping_charge']) && is_numeric($params['shipping_charge'])) {
            $deliveryCharge = max(0.0, floatval($params['shipping_charge']));
        }
        $deliveryCharge = round($deliveryCharge, 2);

        // Taxable amount includes base PCB subtotal + delivery charge (matching cart calculation)
        $taxableAmount = round($subtotal + $deliveryCharge, 2);

        // GST calculation
        $gstSettings = static::getGstSettings();
        $requestedGstRate = isset($params['gst_rate']) && is_numeric($params['gst_rate'])
            ? (float)$params['gst_rate']
            : $gstSettings['active_rate'];

        // Ensure requested GST rate is valid
        if (!in_array($requestedGstRate, $gstSettings['rates'])) {
            $requestedGstRate = $gstSettings['active_rate'];
        }

        $gstAmount = round($taxableAmount * ($requestedGstRate / 100.0), 2);
        $totalAmount = round($taxableAmount + $gstAmount, 2);
        $unitPrice = $qty > 0 ? round($subtotal / $qty, 2) : 0.0;

        return [
            'pricing_method' => $pricingMethod,
            'manual_price' => $manualPrice,
            'pcb_rate' => $pcbRate,
            'price_per_sqm' => $pricePerSqm,
            'area_per_board_sqm' => round($areaPerBoardSqm, 6),
            'total_area_sqm' => round($totalAreaSqm, 6),
            'subtotal' => $subtotal,
            'delivery_charge' => $deliveryCharge,
            'taxable_amount' => $taxableAmount,
            'gst_rate' => $requestedGstRate,
            'gst_amount' => $gstAmount,
            'total_amount' => $totalAmount,
            'unit_price' => $unitPrice,
            'qty' => $qty,
        ];
    }

    /**
     * Determine authoritatively whether a PCB configuration requires JLCPCB manufacturing / pricing.
     * Delegates to centralized ManufacturingProviderResolver.
     *
     * @param array $data PCB specifications or request payload
     * @return bool
     */
    public static function isJlcpcbRequired(array $data): bool
    {
        return ManufacturingProviderResolver::isJlcpcbRequired($data);
    }

    /**
     * Authoritatively resolve quotation source, order type, and series for an order or cart item.
     * Delegates to centralized ManufacturingProviderResolver.
     *
     * @param array $data PCB specifications or request payload
     * @return array ['quotation_source' => 'internal'|'jlcpcb', 'order_type' => 'normal'|'jlcpcb', 'series' => 'M'|'JL']
     */
    public static function resolveOrderSource(array $data): array
    {
        $res = ManufacturingProviderResolver::resolve($data);
        return [
            'quotation_source' => $res['quotation_source'],
            'order_type' => $res['order_type'],
            'series' => $res['series'],
            'provider' => $res['provider'],
            'is_eligible' => $res['is_eligible'],
            'reasons' => $res['reasons'],
        ];
    }

    /**
     * Determine authoritatively whether a request payload corresponds to the verified Combo customer account.
     * Requires an authoritative database customer record matching designated Combo account attributes.
     *
     * @param array $data
     * @return bool
     */
    public static function isComboCustomerAccount(array $data): bool
    {
        $userId = $data['user_id'] ?? null;
        $userEmail = trim((string)($data['user_email'] ?? $data['email'] ?? ''));

        $user = null;
        if (!empty($userId) && is_numeric($userId) && (int)$userId > 0 && class_exists(\App\Models\PcbUser::class)) {
            $user = \App\Models\PcbUser::find((int)$userId);
        }
        if (!$user && !empty($userEmail) && class_exists(\App\Models\PcbUser::class)) {
            $user = \App\Models\PcbUser::where('email', $userEmail)->first();
        }

        // Must resolve an authoritative customer database record
        if (!$user) {
            return false;
        }

        // Check explicit database account type if column exists on user model
        if (isset($user->account_type) && strtolower(trim((string)$user->account_type)) === 'combo') {
            return true;
        }
        if (isset($user->is_combo) && (bool)$user->is_combo) {
            return true;
        }

        // Match exact/strict Combo customer identity against database user attributes
        $userName = strtolower(trim((string)($user->name ?? '')));
        $userCompany = strtolower(trim((string)($user->company_name ?? '')));
        $userEmailAddr = strtolower(trim((string)($user->email ?? '')));
        $userEmailPrefix = strtolower(trim(strtok($userEmailAddr, '@')));

        $comboNames = ['combo', 'combo (combo)', 'combo account', 'combo customer'];

        if (
            in_array($userName, $comboNames, true) ||
            in_array($userCompany, $comboNames, true) ||
            $userEmailAddr === 'combo@megabytecircuits.com' ||
            ($userEmailPrefix === 'combo' && (in_array($userName, $comboNames, true) || in_array($userCompany, $comboNames, true)))
        ) {
            return true;
        }

        return false;
    }

    /**
     * Authoritatively resolve the final order number series ('J' for Combo customer, otherwise 'M' or 'JL').
     *
     * @param array $data
     * @return string 'J' | 'M' | 'JL'
     */
    public static function resolveSeriesForOrder(array $data): string
    {
        if (static::isComboCustomerAccount($data)) {
            return 'J';
        }

        $sourceRes = static::resolveOrderSource($data);
        return $sourceRes['series'] ?? 'M';
    }
}

