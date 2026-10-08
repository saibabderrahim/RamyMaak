<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductPrice;
use App\Support\BusinessClock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pour un historique cohérent (valid_to), passer par PriceBook::setPrice().
 *
 * @extends Factory<ProductPrice>
 */
class ProductPriceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'price_ht' => fake()->randomElement(['450.00', '900.00', '1200.00', '1850.50']),
            'valid_from' => BusinessClock::today()->subMonth()->format('Y-m-d'),
            'valid_to' => null,
        ];
    }
}
