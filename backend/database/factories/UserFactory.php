<?php

namespace Database\Factories;

use App\Domain\Access\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public const PASSWORD = 'Secret12345678';

    protected static ?string $passwordHash = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->bothify('user.####??'),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('05########'),
            'is_active' => true,
            'must_change_password' => false,
            'password' => static::$passwordHash ??= Hash::make(self::PASSWORD),
        ];
    }

    public function role(Role $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole($role->value));
    }

    public function admin(): static
    {
        return $this->role(Role::Admin);
    }

    public function adv(): static
    {
        return $this->role(Role::Adv);
    }

    public function regional(): static
    {
        return $this->role(Role::Regional);
    }

    public function client(): static
    {
        return $this->role(Role::Client);
    }

    public function mustChangePassword(): static
    {
        return $this->state(fn () => ['must_change_password' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
