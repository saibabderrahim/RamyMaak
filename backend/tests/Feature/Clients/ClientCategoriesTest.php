<?php

use App\Domain\Access\Role;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Region;
use App\Models\RegionAssignment;

beforeEach(function () {
    $this->admin = admin();
    $this->grossiste = ClientCategory::factory()->create(['code' => 'GROSSISTE', 'name' => 'Grossiste']);
    $this->detaillant = ClientCategory::factory()->create(['code' => 'DETAILLANT', 'name' => 'Détaillant']);
});

it('lists the categories for staff, not for client accounts', function () {
    $regional = userWithRole(Role::Regional);

    foreach ([$this->admin, userWithRole(Role::Adv), $regional] as $user) {
        $this->actingAs($user)->getJson('/api/v1/client-categories')
            ->assertOk()
            ->assertJsonPath('data.*.code', ['DETAILLANT', 'GROSSISTE']);
    }

    expectProblem($this->actingAs(userWithRole(Role::Client))->getJson('/api/v1/client-categories'), 403, 'FORBIDDEN');
});

it('counts the clients the user can see (RG-S02)', function () {
    $centre = Region::factory()->create();
    $est = Region::factory()->create();
    Client::factory()->count(2)->create(['category_id' => $this->grossiste->id, 'region_id' => $centre->id]);
    Client::factory()->inactive()->create(['category_id' => $this->grossiste->id, 'region_id' => $centre->id]);
    Client::factory()->create(['category_id' => $this->grossiste->id, 'region_id' => $est->id]);
    $regional = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['user_id' => $regional->id, 'region_id' => $centre->id]);

    $this->actingAs($this->admin)->getJson('/api/v1/client-categories')
        ->assertExactJson(['data' => [
            ['id' => $this->detaillant->id, 'code' => 'DETAILLANT', 'name' => 'Détaillant', 'clients_count' => 0],
            ['id' => $this->grossiste->id, 'code' => 'GROSSISTE', 'name' => 'Grossiste', 'clients_count' => 4],
        ]]);

    $this->actingAs($regional)->getJson('/api/v1/client-categories')->assertJsonPath('data.1.clients_count', 2);
});

it('reserves creation and modification to client.manage, before any validation', function (Role $role) {
    $this->actingAs(userWithRole($role));

    expectProblem($this->postJson('/api/v1/admin/client-categories', []), 403, 'FORBIDDEN');
    expectProblem($this->patchJson("/api/v1/admin/client-categories/{$this->grossiste->id}", ['name' => '']), 403, 'FORBIDDEN');
})->with([Role::Adv, Role::Regional, Role::Client]);

it('creates a category with an upper-case code, and journals it', function () {
    $this->actingAs($this->admin);

    $response = $this->postJson('/api/v1/admin/client-categories', ['code' => ' superette ', 'name' => 'Superette']);

    $response->assertCreated()->assertExactJson([
        'id' => $response->json('id'),
        'code' => 'SUPERETTE',
        'name' => 'Superette',
        'clients_count' => 0,
    ]);

    $entry = AuditLog::query()->where('subject_type', 'client_category')->where('subject_id', $response->json('id'))->sole();
    expect($entry->log_name)->toBe('referentials')
        ->and($entry->description)->toBe('Catégorie de clients créée')
        ->and($entry->causer_id)->toBe($this->admin->id);
});

it('validates a new category', function (array $payload, string $field) {
    $this->actingAs($this->admin);

    $this->postJson('/api/v1/admin/client-categories', ['code' => 'NEW', 'name' => 'Nouvelle', ...$payload])
        ->assertJsonValidationErrors($field);
})->with([
    'missing code' => [['code' => ''], 'code'],
    'duplicate code, any case' => [['code' => 'grossiste'], 'code'],
    'code with spaces' => [['code' => 'GROS SISTE'], 'code'],
    'code too long' => [['code' => str_repeat('A', 21)], 'code'],
    'missing name' => [['name' => ''], 'name'],
    'duplicate name' => [['name' => 'Grossiste'], 'name'],
]);

it('renames a category but never changes its code', function () {
    $this->actingAs($this->admin);
    Client::factory()->create(['category_id' => $this->grossiste->id]);

    $this->patchJson("/api/v1/admin/client-categories/{$this->grossiste->id}", ['code' => 'GROSSISTE', 'name' => 'Grossiste et demi-gros'])
        ->assertOk()
        ->assertJsonPath('code', 'GROSSISTE')
        ->assertJsonPath('name', 'Grossiste et demi-gros')
        ->assertJsonPath('clients_count', 1);

    $this->patchJson("/api/v1/admin/client-categories/{$this->grossiste->id}", ['code' => 'GROS'])
        ->assertJsonValidationErrors(['code' => __('clients.code_immutable')]);
    $this->patchJson("/api/v1/admin/client-categories/{$this->grossiste->id}", ['name' => 'Détaillant'])
        ->assertJsonValidationErrors('name');
    $this->patchJson("/api/v1/admin/client-categories/{$this->grossiste->id}", ['name' => 'Grossiste et demi-gros'])->assertOk();

    expect($this->grossiste->fresh()->code)->toBe('GROSSISTE');
    expectProblem($this->patchJson('/api/v1/admin/client-categories/999999', ['name' => 'x']), 404, 'NOT_FOUND');
});
