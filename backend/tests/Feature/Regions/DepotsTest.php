<?php

use App\Domain\Access\Role;
use App\Models\AuditLog;
use App\Models\Depot;
use App\Models\Region;

beforeEach(function () {
    $this->admin = admin();
    $this->blida = Depot::factory()->create(['code' => 'BLIDA', 'name' => 'Dépôt Blida', 'address' => 'Zone industrielle, Blida']);
    $this->setif = Depot::factory()->create(['code' => 'SETIF', 'name' => 'Dépôt Sétif', 'is_active' => false]);
    Region::factory()->count(2)->create(['depot_id' => $this->blida->id]);
});

it('is reserved to region managers, rights checked before validation', function (Role $role) {
    $this->actingAs(userWithRole($role));

    expectProblem($this->getJson('/api/v1/admin/depots'), 403, 'FORBIDDEN');
    expectProblem($this->postJson('/api/v1/admin/depots', ['code' => '!']), 403, 'FORBIDDEN');
    expectProblem($this->postJson('/api/v1/admin/depots', ['code' => 'ORAN', 'name' => 'Dépôt Oran']), 403, 'FORBIDDEN');
    expectProblem($this->patchJson("/api/v1/admin/depots/{$this->blida->id}", ['name' => '']), 403, 'FORBIDDEN');
    expect(Depot::query()->count())->toBe(2);
})->with([Role::Adv, Role::Regional, Role::Client]);

it('lists every depot, unpaginated, with the number of regions served', function () {
    $this->actingAs($this->admin);

    $this->getJson('/api/v1/admin/depots')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                ['id' => $this->blida->id, 'code' => 'BLIDA', 'name' => 'Dépôt Blida', 'address' => 'Zone industrielle, Blida', 'is_active' => true, 'regions_count' => 2],
                ['id' => $this->setif->id, 'code' => 'SETIF', 'name' => 'Dépôt Sétif', 'address' => $this->setif->address, 'is_active' => false, 'regions_count' => 0],
            ],
        ]);
});

it('creates a depot with an upper-case code and journals it', function () {
    $this->actingAs($this->admin);

    $this->postJson('/api/v1/admin/depots', ['code' => ' oran ', 'name' => 'Dépôt Oran', 'address' => null])
        ->assertCreated()
        ->assertJson(['code' => 'ORAN', 'name' => 'Dépôt Oran', 'address' => null, 'is_active' => true, 'regions_count' => 0]);

    $depot = Depot::query()->where('code', 'ORAN')->sole();
    $entry = AuditLog::query()->where('subject_type', 'depot')->where('subject_id', $depot->id)->sole();
    expect($entry->event)->toBe('created')
        ->and($entry->description)->toBe('Dépôt créé')
        ->and($entry->causer_id)->toBe($this->admin->id);
});

it('validates depot creation', function (array $payload, string $field) {
    $this->actingAs($this->admin);

    $this->postJson('/api/v1/admin/depots', ['code' => 'ORAN', 'name' => 'Dépôt Oran', ...$payload])
        ->assertJsonValidationErrors($field);
})->with([
    'missing code' => [['code' => null], 'code'],
    'duplicate code, any case' => [['code' => 'blida'], 'code'],
    'code with spaces' => [['code' => 'ORAN EST'], 'code'],
    'code too long' => [['code' => str_repeat('D', 21)], 'code'],
    'missing name' => [['name' => ''], 'name'],
    'address too long' => [['address' => str_repeat('a', 256)], 'address'],
    'invalid status' => [['is_active' => 'oui'], 'is_active'],
]);

it('updates name, address and status, and journals the change', function () {
    $this->actingAs($this->admin);

    $this->patchJson("/api/v1/admin/depots/{$this->blida->id}", ['name' => 'Dépôt de Blida', 'address' => null, 'is_active' => false])
        ->assertOk()
        ->assertJson(['code' => 'BLIDA', 'name' => 'Dépôt de Blida', 'address' => null, 'is_active' => false, 'regions_count' => 2]);

    $entry = AuditLog::query()->where('subject_type', 'depot')->where('subject_id', $this->blida->id)->where('event', 'updated')->sole();
    expect($entry->description)->toBe('Dépôt modifié')
        ->and($entry->properties['old']['name'])->toBe('Dépôt Blida')
        ->and($entry->properties['attributes']['is_active'])->toBeFalse();
});

it('keeps the depot code', function () {
    $this->actingAs($this->admin);

    $this->patchJson("/api/v1/admin/depots/{$this->blida->id}", ['code' => 'blida', 'name' => 'Blida'])->assertOk();
    $this->patchJson("/api/v1/admin/depots/{$this->blida->id}", ['code' => 'BLIDA2'])
        ->assertJsonValidationErrors(['code' => __('regions.code_immutable')]);

    expect($this->blida->fresh()->code)->toBe('BLIDA');
});

it('validates depot updates and answers 404 for unknown depots', function () {
    $this->actingAs($this->admin);

    $this->patchJson("/api/v1/admin/depots/{$this->blida->id}", ['name' => ''])->assertJsonValidationErrors('name');
    $this->patchJson("/api/v1/admin/depots/{$this->blida->id}", ['is_active' => null])->assertJsonValidationErrors('is_active');
    expectProblem($this->patchJson('/api/v1/admin/depots/999999', ['name' => 'X']), 404, 'NOT_FOUND');
});
