<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PcbPricingSetting;
use Illuminate\Support\Facades\Schema;

class PcbPricingController extends Controller
{
    public static function getDefaultFixedCosts()
    {
        return [
            '1' => ['1' => 3100, '3' => 2100, '5' => 1600, '7' => 1500, '10' => 1400, '20' => 1000],
            '2' => ['1' => 8100, '3' => 4100, '5' => 2600, '7' => 2200, '10' => 1900, '20' => 1400],
            '4' => ['20' => 6000],
            '6' => ['20' => 7000],
            '8' => ['20' => 8000],
            '10' => ['20' => 9000]
        ];
    }

    public static function getDefaultPriceTiers()
    {
        $getStandardPrices = function() {
            return [
                '1' => [
                    "0.5 or less" => [4.62, 3.08, 2.31, 1.925, 1.54],
                    "0.51 to 1" => [4.62, 3.08, 2.31, 1.925, 1.54],
                    "1.01 to 2" => [3.08, 1.54, 1.386, 1.078, 0.77],
                    "2.01 to 3" => [3.08, 1.54, 1.386, 0.886, 0.539],
                    "3.01 to 9.99" => [0, 1.54, 1.155, 0.847, 0.539]
                ],
                '2' => [
                    "0.5 or less" => [5.28, 4.62, 3.3, 2.64, 1.98],
                    "0.51 to 1" => [5.28, 3.96, 2.64, 2.31, 1.98],
                    "1.01 to 2" => [0, 2.64, 2.31, 1.816, 1.32],
                    "2.01 to 3" => [0, 0, 1.848, 1.584, 1.32],
                    "3.01 to 9.99" => [0, 0, 0, 1.518, 1.32]
                ],
                '4' => [
                    "0.5 or less" => [7], "0.51 to 1" => [7], "1.01 to 2" => [4.2], "2.01 to 3" => [4.2], "3.01 to 9.99" => [4.2]
                ],
                '6' => [
                    "0.5 or less" => [9.8], "0.51 to 1" => [9.8], "1.01 to 2" => [7], "2.01 to 3" => [7], "3.01 to 9.99" => [7]
                ],
                '8' => [
                    "0.5 or less" => [7], "0.51 to 1" => [7], "1.01 to 2" => [4.2], "2.01 to 3" => [4.2], "3.01 to 9.99" => [4.2]
                ],
                '10' => [
                    "0.5 or less" => [9.8], "0.51 to 1" => [9.8], "1.01 to 2" => [7], "2.01 to 3" => [7], "3.01 to 9.99" => [7]
                ]
            ];
        };

        $getOtherMask1ozPrices = function() {
            return [
                '1' => [
                    "0.5 or less" => [5.39, 3.85, 3.08, 2.695, 2.31],
                    "0.51 to 1" => [5.39, 3.85, 3.08, 2.695, 2.31],
                    "1.01 to 2" => [3.85, 1.694, 1.54, 1.232, 0.924],
                    "2.01 to 3" => [3.85, 1.694, 1.54, 0.979, 0.57],
                    "3.01 to 9.99" => [0, 1.694, 1.309, 0.939, 0.57]
                ],
                '2' => [
                    "0.5 or less" => [6.6, 5.94, 3.96, 3.136, 2.31],
                    "0.51 to 1" => [6.6, 5.28, 3.3, 2.806, 2.31],
                    "1.01 to 2" => [0, 3.036, 2.64, 2.146, 1.65],
                    "2.01 to 3" => [0, 0, 1.98, 1.782, 1.584],
                    "3.01 to 9.99" => [0, 0, 0, 1.65, 1.584]
                ],
                '4' => ["0.5 or less" => [7], "0.51 to 1" => [7], "1.01 to 2" => [4.2], "2.01 to 3" => [4.2], "3.01 to 9.99" => [4.2]],
                '6' => ["0.5 or less" => [9.8], "0.51 to 1" => [9.8], "1.01 to 2" => [7], "2.01 to 3" => [7], "3.01 to 9.99" => [7]],
                '8' => ["0.5 or less" => [7], "0.51 to 1" => [7], "1.01 to 2" => [4.2], "2.01 to 3" => [4.2], "3.01 to 9.99" => [4.2]],
                '10' => ["0.5 or less" => [9.8], "0.51 to 1" => [9.8], "1.01 to 2" => [7], "2.01 to 3" => [7], "3.01 to 9.99" => [7]]
            ];
        };

        $getGreenMask1ozOtherThicknessPrices = function() {
            return [
                '1' => [
                    "0.5 or less" => [6.93, 4.62, 3.465, 2.888, 2.31],
                    "0.51 to 1" => [6.93, 4.62, 3.465, 2.888, 2.31],
                    "1.01 to 2" => [4.62, 2.31, 2.079, 1.617, 1.155],
                    "2.01 to 3" => [4.62, 2.31, 1.848, 1.617, 1.155],
                    "3.01 to 9.99" => [0, 2.31, 1.733, 1.271, 0.809]
                ],
                '2' => [
                    "0.5 or less" => [7.92, 6.93, 4.95, 3.96, 2.97],
                    "0.51 to 1" => [7.92, 5.94, 3.96, 3.466, 2.97],
                    "1.01 to 2" => [0, 3.96, 3.466, 2.723, 1.98],
                    "2.01 to 3" => [0, 0, 2.442, 2.212, 1.98],
                    "3.01 to 9.99" => [0, 0, 0, 2.278, 1.98]
                ],
                '4' => ["0.5 or less" => [7], "0.51 to 1" => [7], "1.01 to 2" => [4.2], "2.01 to 3" => [4.2], "3.01 to 9.99" => [4.2]],
                '6' => ["0.5 or less" => [9.8], "0.51 to 1" => [9.8], "1.01 to 2" => [7], "2.01 to 3" => [7], "3.01 to 9.99" => [7]],
                '8' => ["0.5 or less" => [7], "0.51 to 1" => [7], "1.01 to 2" => [4.2], "2.01 to 3" => [4.2], "3.01 to 9.99" => [4.2]],
                '10' => ["0.5 or less" => [9.8], "0.51 to 1" => [9.8], "1.01 to 2" => [7], "2.01 to 3" => [7], "3.01 to 9.99" => [7]]
            ];
        };

        $getOtherMask1ozOtherThicknessPrices = function() {
            return [
                '1' => [
                    "0.5 or less" => [8.085, 5.775, 4.62, 4.043, 3.465],
                    "0.51 to 1" => [8.085, 5.775, 4.62, 4.043, 3.465],
                    "1.01 to 2" => [5.775, 2.541, 2.31, 1.848, 1.386],
                    "2.01 to 3" => [5.775, 2.541, 2.079, 1.467, 0.855],
                    "3.01 to 9.99" => [0, 2.541, 1.964, 1.41, 0.855]
                ],
                '2' => [
                    "0.5 or less" => [9.9, 8.91, 5.94, 4.712, 3.466],
                    "0.51 to 1" => [9.9, 7.92, 4.95, 4.208, 3.466],
                    "1.01 to 2" => [0, 4.554, 3.96, 3.234, 2.476],
                    "2.01 to 3" => [0, 0, 2.64, 2.508, 2.376],
                    "3.01 to 9.99" => [0, 0, 0, 2.508, 2.376]
                ],
                '4' => ["0.5 or less" => [7], "0.51 to 1" => [7], "1.01 to 2" => [4.2], "2.01 to 3" => [4.2], "3.01 to 9.99" => [4.2]],
                '6' => ["0.5 or less" => [9.8], "0.51 to 1" => [9.8], "1.01 to 2" => [7], "2.01 to 3" => [7], "3.01 to 9.99" => [7]],
                '8' => ["0.5 or less" => [7], "0.51 to 1" => [7], "1.01 to 2" => [4.2], "2.01 to 3" => [4.2], "3.01 to 9.99" => [4.2]],
                '10' => ["0.5 or less" => [9.8], "0.51 to 1" => [9.8], "1.01 to 2" => [7], "2.01 to 3" => [7], "3.01 to 9.99" => [7]]
            ];
        };

        $getGreenMask2ozPrices = function() {
            return [
                '1' => [
                    "0.5 or less" => [9.24, 6.16, 4.62, 3.85, 3.08],
                    "0.51 to 1" => [9.24, 6.16, 4.62, 3.85, 3.08],
                    "1.01 to 2" => [6.16, 3.08, 2.772, 2.156, 1.54],
                    "2.01 to 3" => [6.16, 3.08, 2.464, 1.771, 1.078],
                    "3.01 to 9.99" => [0, 3.08, 2.31, 1.694, 1.078]
                ],
                '2' => [
                    "0.5 or less" => [10.56, 9.24, 6.6, 5.28, 3.96],
                    "0.51 to 1" => [10.56, 7.92, 5.28, 4.62, 3.96],
                    "1.01 to 2" => [0, 5.28, 4.62, 3.63, 2.64],
                    "2.01 to 3" => [0, 0, 3.3, 3.036, 2.64],
                    "3.01 to 9.99" => [0, 0, 0, 3.036, 2.64]
                ],
                '4' => ["0.5 or less" => [7], "0.51 to 1" => [7], "1.01 to 2" => [4.2], "2.01 to 3" => [4.2], "3.01 to 9.99" => [4.2]],
                '6' => ["0.5 or less" => [9.8], "0.51 to 1" => [9.8], "1.01 to 2" => [7], "2.01 to 3" => [7], "3.01 to 9.99" => [7]],
                '8' => ["0.5 or less" => [7], "0.51 to 1" => [7], "1.01 to 2" => [4.2], "2.01 to 3" => [4.2], "3.01 to 9.99" => [4.2]],
                '10' => ["0.5 or less" => [9.8], "0.51 to 1" => [9.8], "1.01 to 2" => [7], "2.01 to 3" => [7], "3.01 to 9.99" => [7]]
            ];
        };

        $getOtherMask2ozPrices = function() {
            return [
                '1' => [
                    "0.5 or less" => [10.78, 7.7, 6.16, 5.39, 4.62],
                    "0.51 to 1" => [10.78, 7.7, 6.16, 5.39, 4.62],
                    "1.01 to 2" => [7.7, 3.388, 3.08, 2.464, 1.848],
                    "2.01 to 3" => [7.7, 3.388, 2.772, 1.956, 1.14],
                    "3.01 to 9.99" => [0, 3.388, 2.618, 1.879, 1.14]
                ],
                '2' => [
                    "0.5 or less" => [13.2, 11.88, 7.92, 6.27, 4.62],
                    "0.51 to 1" => [13.2, 10.56, 6.6, 5.61, 4.62],
                    "1.01 to 2" => [0, 6.072, 5.28, 4.29, 3.3],
                    "2.01 to 3" => [0, 0, 3.696, 3.432, 3.168],
                    "3.01 to 9.99" => [0, 0, 0, 3.3, 3.168]
                ],
                '4' => ["0.5 or less" => [7], "0.51 to 1" => [7], "1.01 to 2" => [4.2], "2.01 to 3" => [4.2], "3.01 to 9.99" => [4.2]],
                '6' => ["0.5 or less" => [9.8], "0.51 to 1" => [9.8], "1.01 to 2" => [7], "2.01 to 3" => [7], "3.01 to 9.99" => [7]],
                '8' => ["0.5 or less" => [7], "0.51 to 1" => [7], "1.01 to 2" => [4.2], "2.01 to 3" => [4.2], "3.01 to 9.99" => [4.2]],
                '10' => ["0.5 or less" => [9.8], "0.51 to 1" => [9.8], "1.01 to 2" => [7], "2.01 to 3" => [7], "3.01 to 9.99" => [7]]
            ];
        };

        $getGreenMask2ozOtherThicknessPrices = function() {
            return [
                '1' => [
                    "0.5 or less" => [13.86, 9.24, 6.93, 5.775, 4.62],
                    "0.51 to 1" => [13.86, 9.24, 6.93, 5.775, 4.62],
                    "1.01 to 2" => [9.24, 4.62, 4.158, 3.234, 2.31],
                    "2.01 to 3" => [9.24, 4.62, 3.696, 2.657, 1.617],
                    "3.01 to 9.99" => [0, 4.62, 3.465, 2.541, 1.617]
                ],
                '2' => [
                    "0.5 or less" => [15.84, 13.86, 9.9, 7.92, 5.94],
                    "0.51 to 1" => [15.84, 11.88, 7.92, 6.93, 5.94],
                    "1.01 to 2" => [0, 7.92, 6.93, 5.446, 3.96],
                    "2.01 to 3" => [0, 0, 4.752, 4.554, 3.96],
                    "3.01 to 9.99" => [0, 0, 0, 4.554, 3.96]
                ],
                '4' => ["0.5 or less" => [7], "0.51 to 1" => [7], "1.01 to 2" => [4.2], "2.01 to 3" => [4.2], "3.01 to 9.99" => [4.2]],
                '6' => ["0.5 or less" => [9.8], "0.51 to 1" => [9.8], "1.01 to 2" => [7], "2.01 to 3" => [7], "3.01 to 9.99" => [7]],
                '8' => ["0.5 or less" => [7], "0.51 to 1" => [7], "1.01 to 2" => [4.2], "2.01 to 3" => [4.2], "3.01 to 9.99" => [4.2]],
                '10' => ["0.5 or less" => [9.8], "0.51 to 1" => [9.8], "1.01 to 2" => [7], "2.01 to 3" => [7], "3.01 to 9.99" => [7]]
            ];
        };

        $getOtherMask2ozOtherThicknessPrices = function() {
            return [
                '1' => [
                    "0.5 or less" => [16.17, 11.55, 9.24, 8.085, 6.93],
                    "0.51 to 1" => [16.17, 11.55, 9.24, 8.085, 6.93],
                    "1.01 to 2" => [11.55, 5.082, 4.62, 3.696, 2.772],
                    "2.01 to 3" => [11.55, 5.082, 4.158, 2.941, 1.709],
                    "3.01 to 9.99" => [0, 5.082, 3.927, 2.818, 1.709]
                ],
                '2' => [
                    "0.5 or less" => [19.8, 17.82, 11.88, 9.406, 6.93],
                    "0.51 to 1" => [19.8, 15.84, 9.9, 8.416, 6.93],
                    "1.01 to 2" => [0, 9.108, 7.92, 6.436, 4.95],
                    "2.01 to 3" => [0, 0, 5.148, 4.95, 4.752],
                    "3.01 to 9.99" => [0, 0, 0, 4.95, 4.752]
                ],
                '4' => ["0.5 or less" => [7], "0.51 to 1" => [7], "1.01 to 2" => [4.2], "2.01 to 3" => [4.2], "3.01 to 9.99" => [4.2]],
                '6' => ["0.5 or less" => [9.8], "0.51 to 1" => [9.8], "1.01 to 2" => [7], "2.01 to 3" => [7], "3.01 to 9.99" => [7]],
                '8' => ["0.5 or less" => [7], "0.51 to 1" => [7], "1.01 to 2" => [4.2], "2.01 to 3" => [4.2], "3.01 to 9.99" => [4.2]],
                '10' => ["0.5 or less" => [9.8], "0.51 to 1" => [9.8], "1.01 to 2" => [7], "2.01 to 3" => [7], "3.01 to 9.99" => [7]]
            ];
        };

        return [
            'Green' => [
                '1oz' => [
                    '1.6' => $getStandardPrices(),
                    'other' => $getGreenMask1ozOtherThicknessPrices()
                ],
                '2oz' => [
                    '1.6' => $getGreenMask2ozPrices(),
                    'other' => $getGreenMask2ozOtherThicknessPrices()
                ]
            ],
            'Other' => [
                '1oz' => [
                    '1.6' => $getOtherMask1ozPrices(),
                    'other' => $getOtherMask1ozOtherThicknessPrices()
                ],
                '2oz' => [
                    '1.6' => $getOtherMask2ozPrices(),
                    'other' => $getOtherMask2ozOtherThicknessPrices()
                ]
            ]
        ];
    }

    public static function getDefaultShippingOptions()
    {
        return [
            ['key' => 'standard', 'location' => 'Standard', 'method' => 'Standard', 'rate' => 0],
            ['key' => 'plus', 'location' => 'Plus', 'method' => 'Plus', 'rate' => 150],
            ['key' => 'fasttrack', 'location' => 'Fasttrack', 'method' => 'Fasttrack', 'rate' => 450],
        ];
    }

    public function getPricingConfig()
    {
        try {
            $fixedCosts = null;
            $priceTiers = null;
            $shippingOptions = null;

            if (Schema::hasTable('pcb_pricing_settings')) {
                $fixedCostsRow = PcbPricingSetting::where('key', 'fixed_costs')->first();
                if ($fixedCostsRow) {
                    $fixedCosts = $fixedCostsRow->value;
                }

                $priceTiersRow = PcbPricingSetting::where('key', 'price_tiers')->first();
                if ($priceTiersRow) {
                    $priceTiers = $priceTiersRow->value;
                }

                $shippingOptionsRow = PcbPricingSetting::where('key', 'shipping_options')->first();
                if ($shippingOptionsRow) {
                    $shippingOptions = $shippingOptionsRow->value;
                }
            }

            if (!$fixedCosts) {
                $fixedCosts = self::getDefaultFixedCosts();
            }

            if (!$priceTiers) {
                $priceTiers = self::getDefaultPriceTiers();
            }

            if (!$shippingOptions) {
                $shippingOptions = self::getDefaultShippingOptions();
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'fixedCosts' => $fixedCosts,
                    'priceTiers' => $priceTiers,
                    'shippingOptions' => $shippingOptions
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => $th->getMessage(),
                'data' => [
                    'fixedCosts' => self::getDefaultFixedCosts(),
                    'priceTiers' => self::getDefaultPriceTiers(),
                    'shippingOptions' => self::getDefaultShippingOptions()
                ]
            ]);
        }
    }

    public function updatePricingConfig(Request $request)
    {
        try {
            $request->validate([
                'fixedCosts' => 'nullable|array',
                'priceTiers' => 'nullable|array',
                'shippingOptions' => 'nullable|array'
            ]);

            if ($request->has('fixedCosts')) {
                PcbPricingSetting::updateOrCreate(
                    ['key' => 'fixed_costs'],
                    ['value' => $request->input('fixedCosts'), 'description' => 'Fixed costs per layer and day']
                );
            }

            if ($request->has('priceTiers')) {
                PcbPricingSetting::updateOrCreate(
                    ['key' => 'price_tiers'],
                    ['value' => $request->input('priceTiers'), 'description' => 'Variable price tiers per mask, copper weight, thickness, and area']
                );
            }

            if ($request->has('shippingOptions')) {
                PcbPricingSetting::updateOrCreate(
                    ['key' => 'shipping_options'],
                    ['value' => $request->input('shippingOptions'), 'description' => 'Dynamic shipping rates per delivery method']
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'PCB Pricing Calculations updated successfully'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update pricing settings: ' . $th->getMessage()
            ], 500);
        }
    }

    public function resetPricingConfig()
    {
        try {
            PcbPricingSetting::whereIn('key', ['fixed_costs', 'price_tiers', 'shipping_options'])->delete();
            return response()->json([
                'success' => true,
                'message' => 'PCB Pricing reset to factory defaults'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }
}
