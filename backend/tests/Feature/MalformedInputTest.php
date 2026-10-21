<?php

use App\Models\Depot;
use App\Models\Region;

// Valeurs imbriquées dans les filtres ou les champs : 422, jamais 500 (§11.4).

beforeEach(function () {
    $this->actingAs(admin());
});

it('answers 422 to a nested filter value', function (string $url, string $field) {
    $this->getJson($url)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['errors' => [$field]]);
})->with([
    'client status' => ['/api/v1/clients?filter[status][0][]=x', 'filter.status'],
    'client search' => ['/api/v1/clients?filter[search][0][]=x', 'filter.search'],
    'product search' => ['/api/v1/products?filter[search][a][]=x', 'filter.search'],
    'region search' => ['/api/v1/regions?filter[search][a][]=x', 'filter.search'],
    'user role' => ['/api/v1/admin/users?filter[role][0][]=admin', 'filter.role'],
]);

it('still accepts a search sent as a flat list', function () {
    $this->getJson('/api/v1/admin/users?filter[search][]=adm')->assertOk();
});

it('answers 422 to a code sent as an array on a region or a depot', function () {
    $region = Region::factory()->create(['code' => 'CENTRE']);
    $depot = Depot::factory()->create(['code' => 'BLIDA']);

    $this->patchJson("/api/v1/admin/regions/{$region->id}", ['code' => ['x']])->assertUnprocessable()->assertJsonStructure(['errors' => ['code']]);
    $this->patchJson("/api/v1/admin/depots/{$depot->id}", ['code' => ['x']])->assertUnprocessable()->assertJsonStructure(['errors' => ['code']]);
});
