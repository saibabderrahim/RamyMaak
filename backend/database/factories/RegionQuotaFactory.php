<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\RegionDay;
use App\Models\RegionQuota;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegionQuota>
 */
class RegionQuotaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'region_day_id' => RegionDay::factory(),
            'product_id' => Product::factory(),
            'quantity' => 0,
            'draft_quantity' => fake()->numberBetween(10, 500),
        ];
    }
}
