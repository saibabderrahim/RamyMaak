<?php

namespace Database\Factories;

use App\Domain\Reasons\ReasonType;
use App\Models\ReasonCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Motif actif, sans commentaire exigé. Les motifs par défaut (migration)
 * existent déjà dans chaque test : choisir un code qui n'y figure pas.
 *
 * @extends Factory<ReasonCode>
 */
class ReasonCodeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(ReasonType::cases()),
            'code' => 'TEST_'.fake()->unique()->numerify('####'),
            'label' => 'Motif '.fake()->unique()->numerify('####'),
            'requires_comment' => false,
            'is_active' => true,
            'sort_order' => 100,
        ];
    }

    public function type(ReasonType $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function requiringComment(): static
    {
        return $this->state(fn () => ['requires_comment' => true]);
    }
}
