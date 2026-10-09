<?php

use App\Domain\Access\Role;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductFamily;

beforeEach(function () {
    $this->admin = admin();
});

it('lists the families by display order with their product counts', function () {
    $this->actingAs(userWithRole(Role::Regional));
    $juices = ProductFamily::factory()->create(['code' => 'JUS', 'name' => 'Jus', 'sort_order' => 20]);
    $milk = ProductFamily::factory()->create(['code' => 'LAIT', 'name' => 'Lait', 'sort_order' => 10]);
    $nectars = ProductFamily::factory()->create(['code' => 'NECTAR', 'name' => 'Nectars', 'parent_id' => $juices->id, 'sort_order' => 30]);
    Product::factory()->count(2)->create(['family_id' => $juices->id]);
    Product::factory()->inactive()->create(['family_id' => $juices->id]);

    $this->getJson('/api/v1/product-families')
        ->assertOk()
        ->assertJsonPath('data.*.code', ['LAIT', 'JUS', 'NECTAR'])
        ->assertJsonPath('data.1', [
            'id' => $juices->id, 'code' => 'JUS', 'name' => 'Jus', 'parent_id' => null, 'sort_order' => 20, 'products_count' => 3,
        ])
        ->assertJsonPath('data.2.parent_id', $juices->id)
        ->assertJsonPath('data.0.products_count', 0)
        ->assertJsonMissingPath('meta');

    expect($milk->id)->toBeInt()->and($nectars->id)->toBeInt();
});

it('counts only active products for a client account', function () {
    $this->actingAs(userWithRole(Role::Client));
    $juices = ProductFamily::factory()->create(['code' => 'JUS']);
    Product::factory()->count(2)->create(['family_id' => $juices->id]);
    Product::factory()->inactive()->create(['family_id' => $juices->id]);

    $this->getJson('/api/v1/product-families')->assertJsonPath('data.0.products_count', 2);
});

it('reserves family management to product.manage', function (Role $role) {
    $family = ProductFamily::factory()->create();
    $this->actingAs(userWithRole($role));

    expectProblem($this->postJson('/api/v1/admin/product-families', []), 403, 'FORBIDDEN');
    expectProblem($this->patchJson("/api/v1/admin/product-families/{$family->id}", ['name' => '']), 403, 'FORBIDDEN');
})->with([Role::Adv, Role::Regional, Role::Client]);

it('creates a family and journals it', function () {
    $this->actingAs($this->admin);
    $juices = ProductFamily::factory()->create(['code' => 'JUS']);

    $this->postJson('/api/v1/admin/product-families', [
        'code' => ' nectar ',
        'name' => 'Nectars',
        'parent_id' => $juices->id,
        'sort_order' => 15,
    ])->assertCreated()
        ->assertJson(['code' => 'NECTAR', 'name' => 'Nectars', 'parent_id' => $juices->id, 'sort_order' => 15, 'products_count' => 0]);

    $family = ProductFamily::query()->where('code', 'NECTAR')->sole();
    $log = AuditLog::query()->where('subject_type', 'product_family')->where('subject_id', $family->id)->sole();
    expect($log->log_name)->toBe('referentials')
        ->and($log->description)->toBe('Famille de produits créée')
        ->and($log->causer_id)->toBe($this->admin->id);

    // Ordre d'affichage par défaut.
    $this->postJson('/api/v1/admin/product-families', ['code' => 'LAIT', 'name' => 'Lait'])
        ->assertCreated()
        ->assertJsonPath('sort_order', 0)
        ->assertJsonPath('parent_id', null);
});

it('validates family creation', function (array $payload, string $field) {
    $this->actingAs($this->admin);
    ProductFamily::factory()->create(['code' => 'JUS']);

    $this->postJson('/api/v1/admin/product-families', ['code' => 'LAIT', 'name' => 'Lait', ...$payload])
        ->assertJsonValidationErrors($field);
})->with([
    'duplicate code (case-insensitive)' => [['code' => 'jus'], 'code'],
    'code with spaces' => [['code' => 'JUS FRAIS'], 'code'],
    'code too long' => [['code' => str_repeat('A', 21)], 'code'],
    'missing code' => [['code' => null], 'code'],
    'missing name' => [['name' => ''], 'name'],
    'unknown parent' => [['parent_id' => 999], 'parent_id'],
    'negative sort order' => [['sort_order' => -5], 'sort_order'],
]);

it('updates a family and keeps its code', function () {
    $this->actingAs($this->admin);
    $juices = ProductFamily::factory()->create(['code' => 'JUS', 'name' => 'Jus']);
    $nectars = ProductFamily::factory()->create(['code' => 'NECTAR', 'name' => 'Nectars', 'parent_id' => $juices->id]);

    $this->patchJson("/api/v1/admin/product-families/{$nectars->id}", [
        'code' => 'nectar',
        'name' => 'Nectars de fruits',
        'parent_id' => null,
        'sort_order' => 40,
    ])->assertOk()
        ->assertJson(['code' => 'NECTAR', 'name' => 'Nectars de fruits', 'parent_id' => null, 'sort_order' => 40]);

    $this->patchJson("/api/v1/admin/product-families/{$nectars->id}", ['code' => 'NECTARS'])
        ->assertJsonValidationErrors('code');

    $log = AuditLog::query()->where('subject_type', 'product_family')->where('subject_id', $nectars->id)->where('event', 'updated')->sole();
    expect($log->description)->toBe('Famille de produits modifiée')
        ->and($log->properties['old']['parent_id'])->toBe($juices->id)
        ->and($log->properties['attributes']['parent_id'])->toBeNull();
});

it('refuses to attach a family to itself or to one of its descendants', function () {
    $this->actingAs($this->admin);
    $juices = ProductFamily::factory()->create(['code' => 'JUS']);
    $nectars = ProductFamily::factory()->create(['code' => 'NECTAR', 'parent_id' => $juices->id]);
    $premium = ProductFamily::factory()->create(['code' => 'PREMIUM', 'parent_id' => $nectars->id]);
    $milk = ProductFamily::factory()->create(['code' => 'LAIT']);

    $this->patchJson("/api/v1/admin/product-families/{$juices->id}", ['parent_id' => $juices->id])
        ->assertJsonValidationErrors(['parent_id' => 'ni à l\'une de ses sous-familles']);
    $this->patchJson("/api/v1/admin/product-families/{$juices->id}", ['parent_id' => $nectars->id])
        ->assertJsonValidationErrors('parent_id');
    $this->patchJson("/api/v1/admin/product-families/{$juices->id}", ['parent_id' => $premium->id])
        ->assertJsonValidationErrors('parent_id');

    expect($juices->fresh()->parent_id)->toBeNull();

    // Un déplacement sous une autre branche reste possible.
    $this->patchJson("/api/v1/admin/product-families/{$nectars->id}", ['parent_id' => $milk->id])
        ->assertOk()
        ->assertJsonPath('parent_id', $milk->id);
    $this->patchJson("/api/v1/admin/product-families/{$juices->id}", ['parent_id' => $premium->id])
        ->assertOk()
        ->assertJsonPath('parent_id', $premium->id);
});

it('answers 404 for an unknown family', function () {
    $this->actingAs($this->admin);

    expectProblem($this->patchJson('/api/v1/admin/product-families/999', ['name' => 'x']), 404, 'NOT_FOUND');
});
