<?php

namespace Database\Factories;

use App\Models\ProductFamily;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductFamily>
 */
class ProductFamilyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('FAM-###'),
            'name' => fake()->randomElement(['Jus', 'Lait', 'Lben', 'Boissons gazeuses', 'Yaourts']).' '.fake()->numberBetween(1, 999),
            'parent_id' => null,
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }
}
