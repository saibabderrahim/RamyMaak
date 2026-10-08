<?php

namespace Database\Factories;

use App\Models\ClientCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientCategory>
 */
class ClientCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('CAT-###'),
            'name' => fake()->unique()->randomElement(['Grossiste', 'Distributeur', 'Détaillant', 'Superette', 'Hôtellerie', 'Collectivité']).' '.fake()->numberBetween(1, 999),
        ];
    }
}
