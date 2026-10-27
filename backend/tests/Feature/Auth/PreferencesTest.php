<?php

use App\Domain\Access\Role;
use App\Models\AuditLog;

// PATCH /auth/me/preferences (contrat du lot 8, §4, EF-NOT-04).

it('turns the reminders off and on for every role, with the shape of GET /auth/me', function (Role $role) {
    $user = userWithRole($role);
    $this->actingAs($user);
    expect($user->fresh()->reminders_enabled)->toBeTrue();

    $response = $this->patchJson('/api/v1/auth/me/preferences', ['reminders' => false])
        ->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('preferences', ['reminders' => false]);

    expect($user->fresh()->reminders_enabled)->toBeFalse()
        ->and($response->json())->toBe($this->getJson('/api/v1/auth/me')->assertOk()->json());

    $this->patchJson('/api/v1/auth/me/preferences', ['reminders' => true])
        ->assertOk()
        ->assertJsonPath('preferences.reminders', true);
    expect($user->fresh()->reminders_enabled)->toBeTrue();
})->with([Role::Client, Role::Regional, Role::Adv, Role::Admin]);

it('records the change in the auth log', function () {
    $user = userWithRole(Role::Client);
    $this->actingAs($user);

    $this->patchJson('/api/v1/auth/me/preferences', ['reminders' => false])->assertOk();

    $entry = AuditLog::query()->where('log_name', 'auth')->where('event', 'preferences.updated')->sole();
    expect($entry->subject_id)->toBe($user->id)
        ->and($entry->causer_id)->toBe($user->id)
        ->and($entry->properties['reminders'])->toBeFalse();
});

it('rejects a missing or non-boolean value', function (array $body) {
    $user = userWithRole(Role::Regional);
    $this->actingAs($user);

    $response = $this->patchJson('/api/v1/auth/me/preferences', $body);

    expectProblem($response, 422, 'VALIDATION_FAILED');
    expect($response->json('errors'))->toHaveKey('reminders')
        ->and($user->fresh()->reminders_enabled)->toBeTrue();
})->with([
    'missing' => [[]],
    'null' => [['reminders' => null]],
    'text' => [['reminders' => 'non']],
    'list' => [['reminders' => [false]]],
]);

it('requires authentication', function () {
    expectProblem($this->patchJson('/api/v1/auth/me/preferences', ['reminders' => false]), 401, 'UNAUTHENTICATED');
});
