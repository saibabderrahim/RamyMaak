<?php

namespace Database\Factories;

use App\Domain\Referentials\ClientStatus;
use App\Models\Client;
use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify('C#####'),
            'name' => fake()->company(),
            'trade_name' => null,
            'category_id' => null,
            'region_id' => Region::factory(),
            'address' => fake()->streetAddress(),
            'wilaya' => fake()->randomElement(['Alger', 'Blida', 'Oran', 'Constantine', 'Sétif', 'Béjaïa']),
            'commune' => fake()->city(),
            'phone' => fake()->numerify('055#######'),
            'status' => ClientStatus::Active,
        ];
    }

    public function blocked(string $reason = 'Impayés'): static
    {
        return $this->state(fn () => ['status' => ClientStatus::Blocked, 'status_reason' => $reason]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => ClientStatus::Inactive]);
    }
}
