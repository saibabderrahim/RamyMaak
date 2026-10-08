<?php

namespace Database\Factories;

use App\Models\Depot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Depot>
 */
class DepotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('DEP-###'),
            'name' => 'Dépôt '.fake()->city(),
            'address' => fake()->streetAddress(),
            'is_active' => true,
        ];
    }
}
