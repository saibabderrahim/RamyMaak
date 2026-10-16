<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderLine>
 */
class OrderLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'requested_qty' => fake()->numberBetween(1, 50),
            'unit_price_ht' => '900.00',
            'vat_rate' => '19.00',
        ];
    }

    /** Quantité validée (lot 6), au plus la quantité demandée. */
    public function validatedQty(int $quantity): static
    {
        return $this->state(fn () => ['validated_qty' => $quantity]);
    }
}
