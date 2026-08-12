<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PcbPricingSetting;
use App\Http\Controllers\PcbPricingController;

class PcbPricingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        PcbPricingSetting::updateOrCreate(
            ['key' => 'fixed_costs'],
            [
                'value' => PcbPricingController::getDefaultFixedCosts(),
                'description' => 'Fixed setup costs per layer and lead time day'
            ]
        );

        PcbPricingSetting::updateOrCreate(
            ['key' => 'price_tiers'],
            [
                'value' => PcbPricingController::getDefaultPriceTiers(),
                'description' => 'Variable price tiers per mask, copper weight, thickness, and area'
            ]
        );
    }
}
