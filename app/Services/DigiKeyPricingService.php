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
     * Resolve effective margin configuration for a specific product
     */
    public static function getMarginForProduct(?int $productId): array
    {
        if ($productId) {
            $productMargin = DigiKeyProductMargin::where('digikey_product_id', $productId)->first();
            if ($productMargin && $productMargin->is_active) {
                return [
                    'margin_type' => $productMargin->margin_type,
                    'margin_value' => (float) $productMargin->margin_value,
                    'is_custom' => true,
                ];
            }
        }

        $defaultMargin = self::getDefaultMargin();
        return [
            'margin_type' => $defaultMargin['margin_type'],
            'margin_value' => (float) $defaultMargin['margin_value'],
            'is_custom' => false,
        ];
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
     * Calculate tiered customer pricing structure for a product
     */
    public static function calculateCustomerPricing(DigiKeyProduct $product, int $quantity = 1, ?array $marginOverride = null): array
    {
        $marginConfig = $marginOverride ?? self::getMarginForProduct($product->id);
        $marginType = $marginConfig['margin_type'];
        $marginValue = (float) $marginConfig['margin_value'];

        $baseUnitPrice = (float) $product->unit_price;
        $customerBaseUnitPrice = self::applyMarginToUnitPrice($baseUnitPrice, $marginType, $marginValue);

        $rawPricingTiers = self::extractRawStandardPricing($product);
        $customerTiers = [];

        if (!empty($rawPricingTiers)) {
            foreach ($rawPricingTiers as $tier) {
                $breakQty = (int) ($tier['BreakQuantity'] ?? 1);
                $digiKeyTierUnitPrice = (float) ($tier['UnitPrice'] ?? $baseUnitPrice);
                $customerTierUnitPrice = self::applyMarginToUnitPrice($digiKeyTierUnitPrice, $marginType, $marginValue);

                $customerTiers[] = [
                    'BreakQuantity' => $breakQty,
                    'DigiKeyUnitPrice' => $digiKeyTierUnitPrice,
                    'UnitPrice' => $customerTierUnitPrice,
                    'TotalPrice' => round($customerTierUnitPrice * $breakQty, 2),
                ];
            }
        } else {
            // Default 1+ break tier if no quantity breaks exist
            $customerTiers[] = [
                'BreakQuantity' => 1,
                'DigiKeyUnitPrice' => $baseUnitPrice,
                'UnitPrice' => $customerBaseUnitPrice,
                'TotalPrice' => round($customerBaseUnitPrice * 1, 2),
            ];
        }

        // Determine applicable tier unit price for target quantity
        $effectiveUnitPrice = $customerBaseUnitPrice;
        $effectiveBaseUnitPrice = $baseUnitPrice;

        if (!empty($customerTiers)) {
            // Sort by BreakQuantity descending to find highest applicable tier
            usort($customerTiers, function ($a, $b) {
                return $b['BreakQuantity'] <=> $a['BreakQuantity'];
            });

            foreach ($customerTiers as $tier) {
                if ($quantity >= $tier['BreakQuantity']) {
                    $effectiveUnitPrice = $tier['UnitPrice'];
                    $effectiveBaseUnitPrice = $tier['DigiKeyUnitPrice'];
                    break;
                }
            }

            // Re-sort ascending for tier display presentation
            usort($customerTiers, function ($a, $b) {
                return $a['BreakQuantity'] <=> $b['BreakQuantity'];
            });
        }

        $totalLinePrice = round($effectiveUnitPrice * $quantity, 2);

        return [
            'quantity' => $quantity,
            'margin' => [
                'type' => $marginType,
                'value' => $marginValue,
                'is_custom' => $marginConfig['is_custom'] ?? false,
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
                'TotalPrice' => $t['TotalPrice'],
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
