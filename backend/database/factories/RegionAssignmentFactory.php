<?php

namespace Database\Factories;

use App\Domain\Access\Role;
use App\Domain\Referentials\AssignmentKind;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\User;
use App\Support\BusinessClock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegionAssignment>
 */
class RegionAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'region_id' => Region::factory(),
            'user_id' => User::factory()->role(Role::Regional),
            'kind' => AssignmentKind::Titular,
            'starts_on' => BusinessClock::today()->subMonth(),
            'ends_on' => null,
        ];
    }

    public function deputy(?string $startsOn = null, ?string $endsOn = null): static
    {
        return $this->state(fn () => [
            'kind' => AssignmentKind::Deputy,
            'starts_on' => $startsOn ?? BusinessClock::today()->subDay()->format('Y-m-d'),
            'ends_on' => $endsOn ?? BusinessClock::today()->addWeek()->format('Y-m-d'),
        ]);
    }

    public function adv(): static
    {
        return $this->state(fn () => [
            'kind' => AssignmentKind::Adv,
            'user_id' => User::factory()->role(Role::Adv),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()->subHour()]);
    }
}
