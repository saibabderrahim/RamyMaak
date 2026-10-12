<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientLimit;
use App\Models\Product;
use App\Models\RegionDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientLimit>
 */
class ClientLimitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'region_day_id' => RegionDay::factory(),
            'client_id' => Client::factory(),
            'product_id' => Product::factory(),
            'max_qty' => null,
            'draft_max_qty' => fake()->numberBetween(1, 100),
            'has_draft' => true,
        ];
    }

    /** Borne publiée, sans brouillon. */
    public function published(int $max): static
    {
        return $this->state(fn () => ['max_qty' => $max, 'draft_max_qty' => null, 'has_draft' => false]);
    }
}
