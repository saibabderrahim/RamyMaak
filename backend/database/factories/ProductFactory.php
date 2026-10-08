<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductFamily;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $flavour = fake()->randomElement(['Orange', 'Pomme', 'Mangue', 'Fraise', 'Cocktail', 'Raisin']);
        $format = fake()->randomElement(['1 L', '25 cl', '2 L']);

        return [
            'sku' => fake()->unique()->numerify('P####'),
            'name' => "Jus {$flavour} {$format}",
            'short_name' => "{$flavour} {$format}",
            'family_id' => ProductFamily::factory(),
            'format' => $format,
            'sales_unit_label' => "Fardeau 6 × {$format}",
            'units_per_sales_unit' => 6,
            'sales_units_per_pallet' => 80,
            'ean' => fake()->ean13(),
            'vat_rate' => '19.00',
            'is_active' => true,
            'sort_order' => fake()->numberBetween(1, 500),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
