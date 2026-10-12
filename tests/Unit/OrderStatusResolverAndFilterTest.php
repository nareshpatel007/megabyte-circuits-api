<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\OrderStatusResolver;

class OrderStatusResolverAndFilterTest extends TestCase
{
    /**
     * Test that 'traveller' and 'Traveller' are normalized to canonical 'traveler'.
     */
    public function test_traveller_alias_resolution(): void
    {
        // When resolving 'traveller' with double 'l', it should resolve identically to 'traveler'
        $resolvedTraveller = OrderStatusResolver::resolve('Traveller');
        $resolvedTraveler = OrderStatusResolver::resolve('Traveler');

        if ($resolvedTraveler !== null) {
            $this->assertNotNull($resolvedTraveller);
            $this->assertEquals($resolvedTraveler->id, $resolvedTraveller->id);
            $this->assertEquals($resolvedTraveler->name, $resolvedTraveller->name);
        } else {
            // In an unseeded environment, resolve should return same for both
            $this->assertEquals($resolvedTraveler, $resolvedTraveller);
        }
    }

    /**
     * Test layer variant generation logic.
     */
    public function test_layer_variants_for_filtering(): void
    {
        $getVariants = function ($rawLayerInput) {
            $layerNum = intval($rawLayerInput);
            if ($layerNum <= 0) return [];
            return [
                (string)$layerNum,
                "{$layerNum} Layer",
                "{$layerNum} Layers",
                "{$layerNum}layer",
                "{$layerNum}layers",
            ];
        };

        // 1 Layer variants
        $layer1Variants = array_map('strtolower', $getVariants('1'));
        $this->assertContains('1', $layer1Variants);
        $this->assertContains('1 layer', $layer1Variants);
        $this->assertContains('1 layers', $layer1Variants);
        $this->assertNotContains('2', $layer1Variants);
        $this->assertNotContains('2 layer', $layer1Variants);

        // 2 Layer variants
        $layer2Variants = array_map('strtolower', $getVariants('2'));
        $this->assertContains('2', $layer2Variants);
        $this->assertContains('2 layer', $layer2Variants);
        $this->assertContains('2 layers', $layer2Variants);
        $this->assertNotContains('1', $layer2Variants);

        // Empty, null, and non-numeric inputs do not yield 1 or 2 layers
        $this->assertEmpty($getVariants(''));
        $this->assertEmpty($getVariants(null));
        $this->assertEmpty($getVariants('All'));
    }
}
