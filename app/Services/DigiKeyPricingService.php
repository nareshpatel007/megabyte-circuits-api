<?php

namespace App\Services;

use App\Models\DigiKeyProduct;
use App\Models\DigiKeyProductMargin;

class DigiKeyPricingService
{
    /**
     * Get global default DigiKey margin settings
     */
    public static function getDefaultMargin(): array
    {
        $defaultConfig = DigiKeyProductMargin::whereNull('digikey_product_id')->first();
        if ($defaultConfig) {
            return [
                'margin_type' => $defaultConfig->margin_type,
                'margin_value' => (float) $defaultConfig->margin_value,
                'is_active' => (bool) $defaultConfig->is_active,
            ];
        }

        // Fallback default from .env or 0 percentage
        $envType = env('DIGIKEY_DEFAULT_MARGIN_TYPE', 'percentage');
        $envValue = (float) env('DIGIKEY_DEFAULT_MARGIN_VALUE', 0);

        return [
            'margin_type' => in_array($envType, ['percentage', 'fixed']) ? $envType : 'percentage',
            'margin_value' => max(0, $envValue),
            'is_active' => true,
        ];
    }

    /**
     * Resolve effective margin configuration for a product and optionally a specific tier quantity
     */
    public static function getProductMargins(?int $productId): array
    {
        $defaultMargin = self::getDefaultMargin();
        if (!$productId) {
            return [
                'default' => $defaultMargin,
                'tiers' => [],
                'has_custom' => false,
            ];
        }

        $margins = DigiKeyProductMargin::where('digikey_product_id', $productId)
            ->where('is_active', true)
            ->get();

        if ($margins->isEmpty()) {
            return [
                'default' => $defaultMargin,
                'tiers' => [],
                'has_custom' => false,
            ];
        }

        $productDefault = $margins->firstWhere('tier_quantity', null);
        $effectiveDefault = $productDefault ? [
            'margin_type' => $productDefault->margin_type,
            'margin_value' => (float) $productDefault->margin_value,
            'is_custom' => true,
        ] : $defaultMargin;

        $tierMap = [];
        foreach ($margins as $m) {
            if ($m->tier_quantity !== null && $m->tier_quantity > 0) {
                $tierMap[$m->tier_quantity] = [
                    'margin_type' => $m->margin_type,
                    'margin_value' => (float) $m->margin_value,
                    'is_custom' => true,
                ];
            }
        }

        return [
            'default' => $effectiveDefault,
            'tiers' => $tierMap,
            'has_custom' => true,
        ];
    }

    /**
     * Resolve margin for product at a specific tier break quantity
     */
    public static function getMarginForProduct(?int $productId, ?int $tierQty = null): array
    {
        $allMargins = self::getProductMargins($productId);
        if ($tierQty !== null && isset($allMargins['tiers'][$tierQty])) {
            return $allMargins['tiers'][$tierQty];
        }

        return $allMargins['default'];
    }

    /**
     * Apply margin to a single base unit price
     */
    public static function applyMarginToUnitPrice(float $baseUnitPrice, string $marginType, float $marginValue): float
    {
        if ($baseUnitPrice <= 0) {
            return 0.00;
        }

        if ($marginType === 'fixed') {
            $sellingPrice = $baseUnitPrice + $marginValue;
        } else {
            // Percentage margin
            $sellingPrice = $baseUnitPrice * (1 + ($marginValue / 100));
        }

        return max(0.00, round($sellingPrice, 2));
    }

    /**
     * Extract raw DigiKey price break tiers from product data
     */
    public static function extractRawStandardPricing(DigiKeyProduct $product): array
    {
        if (!empty($product->product_variations) && is_array($product->product_variations)) {
            if (!empty($product->product_variations[0]['StandardPricing']) && is_array($product->product_variations[0]['StandardPricing'])) {
                return $product->product_variations[0]['StandardPricing'];
            }
        }

        $raw = is_array($product->raw_response) ? $product->raw_response : json_decode($product->raw_response ?? '{}', true);
        if (!empty($raw['StandardPricing']) && is_array($raw['StandardPricing'])) {
            return $raw['StandardPricing'];
        }

        if (!empty($raw['ProductVariations'][0]['StandardPricing']) && is_array($raw['ProductVariations'][0]['StandardPricing'])) {
            return $raw['ProductVariations'][0]['StandardPricing'];
        }

        return [];
    }

    /**
     * Calculate tiered customer pricing structure for a product with tier-specific margins
     */
    public static function calculateCustomerPricing(DigiKeyProduct $product, int $quantity = 1, ?array $marginOverride = null): array
    {
        $allMargins = self::getProductMargins($product->id);
        $rawPricingTiers = self::extractRawStandardPricing($product);
        $baseUnitPrice = (float) $product->unit_price;

        $customerTiers = [];

        if (!empty($rawPricingTiers)) {
            foreach ($rawPricingTiers as $tier) {
                $breakQty = (int) ($tier['BreakQuantity'] ?? 1);
                $digiKeyTierUnitPrice = (float) ($tier['UnitPrice'] ?? $baseUnitPrice);

                // Determine tier margin (override > tier-specific margin > product default margin > global default margin)
                if ($marginOverride && isset($marginOverride['tiers'][$breakQty])) {
                    $tierMargin = $marginOverride['tiers'][$breakQty];
                } elseif ($marginOverride && isset($marginOverride['margin_type']) && !isset($marginOverride['tiers'])) {
                    $tierMargin = $marginOverride;
                } elseif (isset($allMargins['tiers'][$breakQty])) {
                    $tierMargin = $allMargins['tiers'][$breakQty];
                } else {
                    $tierMargin = $allMargins['default'];
                }

                $mType = $tierMargin['margin_type'];
                $mVal = (float) $tierMargin['margin_value'];
                $customerTierUnitPrice = self::applyMarginToUnitPrice($digiKeyTierUnitPrice, $mType, $mVal);

                $customerTiers[] = [
                    'BreakQuantity' => $breakQty,
                    'DigiKeyUnitPrice' => $digiKeyTierUnitPrice,
                    'MarginType' => $mType,
                    'MarginValue' => $mVal,
                    'UnitPrice' => $customerTierUnitPrice,
                    'TotalPrice' => round($customerTierUnitPrice * $breakQty, 2),
                    'IsCustomMargin' => $tierMargin['is_custom'] ?? false,
                ];
            }
        } else {
            // Default 1+ break tier if no quantity breaks exist
            $tierMargin = $allMargins['default'];
            $mType = $tierMargin['margin_type'];
            $mVal = (float) $tierMargin['margin_value'];
            $customerBaseUnitPrice = self::applyMarginToUnitPrice($baseUnitPrice, $mType, $mVal);

            $customerTiers[] = [
                'BreakQuantity' => 1,
                'DigiKeyUnitPrice' => $baseUnitPrice,
                'MarginType' => $mType,
                'MarginValue' => $mVal,
                'UnitPrice' => $customerBaseUnitPrice,
                'TotalPrice' => round($customerBaseUnitPrice * 1, 2),
                'IsCustomMargin' => $tierMargin['is_custom'] ?? false,
            ];
        }

        // Determine applicable tier unit price & margin for target quantity
        $applicableTier = null;
        if (!empty($customerTiers)) {
            // Sort descending by BreakQuantity to match target quantity
            usort($customerTiers, function ($a, $b) {
                return $b['BreakQuantity'] <=> $a['BreakQuantity'];
            });

            foreach ($customerTiers as $tier) {
                if ($quantity >= $tier['BreakQuantity']) {
                    $applicableTier = $tier;
                    break;
                }
            }

            // Re-sort ascending for display presentation
            usort($customerTiers, function ($a, $b) {
                return $a['BreakQuantity'] <=> $b['BreakQuantity'];
            });
        }

        if (!$applicableTier && !empty($customerTiers)) {
            $applicableTier = $customerTiers[0];
        }

        $effectiveUnitPrice = $applicableTier ? $applicableTier['UnitPrice'] : $baseUnitPrice;
        $effectiveBaseUnitPrice = $applicableTier ? $applicableTier['DigiKeyUnitPrice'] : $baseUnitPrice;
        $effectiveMarginType = $applicableTier ? $applicableTier['MarginType'] : $allMargins['default']['margin_type'];
        $effectiveMarginValue = $applicableTier ? $applicableTier['MarginValue'] : $allMargins['default']['margin_value'];

        $totalLinePrice = round($effectiveUnitPrice * $quantity, 2);

        return [
            'quantity' => $quantity,
            'margin' => [
                'type' => $effectiveMarginType,
                'value' => $effectiveMarginValue,
                'is_custom' => $applicableTier['IsCustomMargin'] ?? false,
            ],
            'base_unit_price' => $effectiveBaseUnitPrice,
            'unit_price' => $effectiveUnitPrice,
            'total_price' => $totalLinePrice,
            'tiers' => $customerTiers,
        ];
    }

    /**
     * Standardize response data for DigiKey product model output
     */
    public static function formatProductResponse(DigiKeyProduct $item, ?array $marginOverride = null): array
    {
        $pricing = self::calculateCustomerPricing($item, 1, $marginOverride);
        $raw = is_array($item->raw_response) ? $item->raw_response : json_decode($item->raw_response ?? '{}', true);

        // Build customer formatted standard pricing array
        $formattedStandardPricing = array_map(function ($t) {
            return [
                'BreakQuantity' => $t['BreakQuantity'],
                'UnitPrice' => $t['UnitPrice'],
                'DigiKeyUnitPrice' => $t['DigiKeyUnitPrice'],
                'MarginType' => $t['MarginType'] ?? 'percentage',
                'MarginValue' => $t['MarginValue'] ?? 0,
                'TotalPrice' => $t['TotalPrice'],
                'IsCustomMargin' => $t['IsCustomMargin'] ?? false,
            ];
        }, $pricing['tiers']);

        $productVariations = !empty($item->product_variations) ? $item->product_variations : [
            [
                'DigiKeyProductNumber' => $item->digikey_product_number,
                'StandardPricing' => $formattedStandardPricing,
                'MinimumOrderQuantity' => 1,
            ]
        ];

        if (!empty($productVariations) && is_array($productVariations)) {
            $productVariations[0]['StandardPricing'] = $formattedStandardPricing;
        }

        return [
            'id' => $item->id,
            'DigiKeyProductNumber' => $item->digikey_product_number,
            'ManufacturerProductNumber' => $item->manufacturer_product_number,
            'Description' => [
                'ProductDescription' => $item->product_description,
                'DetailedDescription' => $item->detailed_description,
            ],
            'Manufacturer' => [
                'Id' => $item->manufacturer_id,
                'Name' => $item->manufacturer_name,
            ],
            'DigiKeyUnitPrice' => (float) $item->unit_price,
            'UnitPrice' => $pricing['unit_price'],
            'Margin' => $pricing['margin'],
            'StandardPricing' => $formattedStandardPricing,
            'ProductUrl' => $item->product_url,
            'DatasheetUrl' => $item->datasheet_url,
            'PhotoUrl' => $item->photo_url,
            'QuantityAvailable' => (int) $item->quantity_available,
            'ProductStatus' => [
                'Status' => $item->product_status ?? 'Active'
            ],
            'Category' => $item->search_keyword,
            'ProductVariations' => $productVariations,
            'MinimumOrderQuantity' => $productVariations[0]['MinimumOrderQuantity'] ?? 1,
            'Parameters' => !empty($item->parameters) ? $item->parameters : ($raw['Parameters'] ?? $raw['ProductAttributes'] ?? []),
            'Classifications' => !empty($item->classifications) ? $item->classifications : ($raw['Classifications'] ?? null),
            'Series' => !empty($item->series) ? $item->series : ($raw['Series'] ?? null),
            'OtherNames' => !empty($item->other_names) ? $item->other_names : ($raw['OtherNames'] ?? []),
            'BaseProductNumber' => !empty($item->base_product_number) ? $item->base_product_number : ($raw['BaseProductNumber'] ?? null),
            'CategoryDetails' => !empty($item->category_details) ? $item->category_details : ($raw['Category'] ?? null),
            'DateLastBuyChance' => $item->date_last_buy_chance ?? ($raw['DateLastBuyChance'] ?? null),
            'ShippingInfo' => !empty($item->shipping_info) ? $item->shipping_info : ($raw['ShippingInfo'] ?? null),
        ];
    }
}
