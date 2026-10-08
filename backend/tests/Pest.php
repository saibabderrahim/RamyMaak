<?php

use App\Domain\Access\Role;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

function userWithRole(Role $role, array $attributes = []): User
{
    return User::factory()->role($role)->create($attributes);
}

function admin(array $attributes = []): User
{
    return userWithRole(Role::Admin, $attributes);
}

/**
 * Vérifie le format « Problem Details » (RFC 9457) et le code métier.
 */
function expectProblem(TestResponse $response, int $status, string $code): void
{
    $response->assertStatus($status)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonStructure(['type', 'title', 'status', 'code', 'detail'])
        ->assertJsonPath('status', $status)
        ->assertJsonPath('code', $code);
}

/**
 * Dans un test, l'application (et donc ses gardes d'authentification) est
 * partagée entre requêtes ; en production chaque requête repart de zéro.
 * À appeler avant une requête censée relire la session.
 */
function forgetGuards(): void
{
    app('auth')->forgetGuards();
}
