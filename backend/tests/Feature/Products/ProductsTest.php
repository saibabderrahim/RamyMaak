<?php

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Referentials\PriceBook;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00', 'Africa/Algiers'));
    $this->admin = admin();
    $this->juices = ProductFamily::factory()->create(['code' => 'JUS', 'name' => 'Jus', 'sort_order' => 10]);
    $this->setPrice = fn (Product $product, string $amount, string $from) => app(PriceBook::class)
        ->setPrice($product, $amount, BusinessClock::parseDay($from), null);
});

it('lets every role read the catalogue but only product.manage write it', function (Role $role) {
    $product = Product::factory()->create(['family_id' => $this->juices->id]);
    $this->actingAs(userWithRole($role));

    $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson("/api/v1/products/{$product->id}")->assertOk()->assertJsonPath('sku', $product->sku);
    $this->getJson('/api/v1/product-families')->assertOk();

    // Droits contrôlés avant la validation : 403 même pour un corps invalide.
    expectProblem($this->postJson('/api/v1/products', []), 403, 'FORBIDDEN');
    expectProblem($this->patchJson("/api/v1/products/{$product->id}", ['vat_rate' => 'x']), 403, 'FORBIDDEN');
})->with([Role::Adv, Role::Regional, Role::Client]);

it('refuses the catalogue to an account without product.view', function () {
    $this->actingAs(User::factory()->create());

    expectProblem($this->getJson('/api/v1/products'), 403, 'FORBIDDEN');
    expectProblem($this->getJson('/api/v1/product-families'), 403, 'FORBIDDEN');
});

it('lets an ADV granted product.manage edit the catalogue (option)', function () {
    $adv = userWithRole(Role::Adv);
    $adv->givePermissionTo(Permission::ProductManage->value);
    $this->actingAs($adv);

    $this->postJson('/api/v1/products', [
        'sku' => 'P0100',
        'name' => 'Jus pomme 1 L',
        'family_id' => $this->juices->id,
        'sales_unit_label' => 'Fardeau 6 × 1 L',
        'units_per_sales_unit' => 6,
        'vat_rate' => 19,
    ])->assertCreated();
});

it('shows only active products to a client account', function () {
    $active = Product::factory()->create(['sku' => 'P0001', 'family_id' => $this->juices->id]);
    $inactive = Product::factory()->inactive()->create(['sku' => 'P0002', 'family_id' => $this->juices->id]);
    $this->actingAs(userWithRole(Role::Client));

    $this->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonPath('data.*.sku', ['P0001'])
        ->assertJsonPath('meta.total', 1);

    $this->getJson("/api/v1/products/{$active->id}")->assertOk();
    // Un produit inactif n'existe pas pour un client.
    expectProblem($this->getJson("/api/v1/products/{$inactive->id}"), 404, 'NOT_FOUND');
    // Le filtre sur le statut est réservé au personnel.
    expectProblem($this->getJson('/api/v1/products?filter[is_active]=0'), 422, 'VALIDATION_FAILED');
});

it('lets the staff see and filter inactive products', function (Role $role) {
    Product::factory()->create(['sku' => 'P0001', 'family_id' => $this->juices->id, 'sort_order' => 1]);
    $inactive = Product::factory()->inactive()->create(['sku' => 'P0002', 'family_id' => $this->juices->id, 'sort_order' => 2]);
    $this->actingAs(userWithRole($role));

    $this->getJson('/api/v1/products')->assertJsonPath('data.*.sku', ['P0001', 'P0002']);
    $this->getJson('/api/v1/products?filter[is_active]=0')->assertJsonPath('data.*.sku', ['P0002']);
    $this->getJson('/api/v1/products?filter[is_active]=true')->assertJsonPath('data.*.sku', ['P0001']);
    $this->getJson("/api/v1/products/{$inactive->id}")->assertOk()->assertJsonPath('is_active', false);
    expectProblem($this->getJson('/api/v1/products?filter[is_active]=peut-etre'), 422, 'VALIDATION_FAILED');
})->with([Role::Admin, Role::Adv, Role::Regional]);

it('searches, filters by family (sub-families included), sorts and paginates', function () {
    $this->actingAs($this->admin);
    $nectars = ProductFamily::factory()->create(['code' => 'NECTAR', 'parent_id' => $this->juices->id]);
    $milk = ProductFamily::factory()->create(['code' => 'LAIT']);
    Product::factory()->create(['sku' => 'P0010', 'name' => 'Jus orange 1 L', 'short_name' => 'Orange 1 L', 'family_id' => $this->juices->id, 'sort_order' => 3]);
    Product::factory()->create(['sku' => 'P0020', 'name' => 'Nectar mangue 1 L', 'short_name' => 'Mangue 1 L', 'family_id' => $nectars->id, 'sort_order' => 1]);
    Product::factory()->create(['sku' => 'P0030', 'name' => 'Lait entier 1 L', 'short_name' => 'Lait 1 L', 'family_id' => $milk->id, 'sort_order' => 2]);

    $this->getJson('/api/v1/products')->assertJsonPath('data.*.sku', ['P0020', 'P0030', 'P0010']);
    $this->getJson('/api/v1/products?sort=name')->assertJsonPath('data.*.sku', ['P0010', 'P0030', 'P0020']);
    $this->getJson('/api/v1/products?sort=-sku')->assertJsonPath('data.*.sku', ['P0030', 'P0020', 'P0010']);

    $this->getJson('/api/v1/products?filter[search]=mangue')->assertJsonPath('data.*.sku', ['P0020']);
    $this->getJson('/api/v1/products?filter[search]=p0030')->assertJsonPath('data.*.sku', ['P0030']);
    $this->getJson('/api/v1/products?filter[search]=Orange 1')->assertJsonPath('data.*.sku', ['P0010']);

    $this->getJson("/api/v1/products?filter[family_id]={$this->juices->id}")->assertJsonPath('data.*.sku', ['P0020', 'P0010']);
    $this->getJson("/api/v1/products?filter[family_id]={$nectars->id},{$milk->id}")->assertJsonPath('data.*.sku', ['P0020', 'P0030']);

    $this->getJson('/api/v1/products?per_page=2')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3);
    $this->getJson('/api/v1/products?per_page=500')->assertJsonPath('meta.per_page', 100);

    expectProblem($this->getJson('/api/v1/products?filter[ean]=1'), 422, 'VALIDATION_FAILED');
    expectProblem($this->getJson('/api/v1/products?sort=vat_rate'), 422, 'VALIDATION_FAILED');
    expectProblem($this->getJson('/api/v1/products?filter[family_id]=abc'), 422, 'VALIDATION_FAILED');
});

it('reads the search term literally', function () {
    $this->actingAs($this->admin);
    Product::factory()->create(['sku' => 'P_10', 'name' => 'Jus orange 1 L', 'family_id' => $this->juices->id]);
    Product::factory()->create(['sku' => 'PX10', 'name' => 'Jus pomme 1 L', 'family_id' => $this->juices->id]);
    Product::factory()->create(['sku' => 'P0050', 'name' => 'Jus 100% pur fruit', 'family_id' => $this->juices->id]);

    // « _ » et « % » ne sont pas des jokers ; une virgule ne découpe pas la recherche.
    $this->getJson('/api/v1/products?filter[search]=P_1')->assertJsonPath('data.*.sku', ['P_10']);
    $this->getJson('/api/v1/products?filter[search]=100%')->assertJsonPath('data.*.sku', ['P0050']);
    $this->getJson('/api/v1/products?filter[search]=orange,pomme')->assertJsonPath('meta.total', 0);
});

it('returns the product with its price in force, TTC and unit prices, and the next price', function () {
    $this->actingAs(userWithRole(Role::Client));
    $product = Product::factory()->create([
        'sku' => 'P0042', 'name' => 'Jus orange 1 L', 'short_name' => 'Orange 1 L', 'family_id' => $this->juices->id,
        'format' => '1 L', 'sales_unit_label' => 'Fardeau 6 × 1 L', 'units_per_sales_unit' => 6,
        'sales_units_per_pallet' => 80, 'ean' => '6130000000420', 'vat_rate' => '19.00', 'is_new' => true,
    ]);
    ($this->setPrice)($product, '850', '2026-09-01');
    ($this->setPrice)($product, '900', '2026-10-01');
    ($this->setPrice)($product, '950', '2026-11-01');
    ($this->setPrice)($product, '990', '2026-12-01');

    $this->getJson("/api/v1/products/{$product->id}")
        ->assertOk()
        ->assertJson([
            'id' => $product->id,
            'sku' => 'P0042',
            'name' => 'Jus orange 1 L',
            'short_name' => 'Orange 1 L',
            'family' => ['id' => $this->juices->id, 'code' => 'JUS', 'name' => 'Jus'],
            'format' => '1 L',
            'sales_unit_label' => 'Fardeau 6 × 1 L',
            'units_per_sales_unit' => 6,
            'sales_units_per_pallet' => 80,
            'ean' => '6130000000420',
            'vat_rate' => 19,
            'is_active' => true,
            'is_new' => true,
            'is_featured' => false,
            'photos' => [],
        ])
        ->assertJsonPath('price', ['price_ht' => 900, 'price_ttc' => 1071, 'unit_price_ht' => 150, 'valid_from' => '2026-10-01'])
        ->assertJsonPath('next_price', ['price_ht' => 950, 'valid_from' => '2026-11-01']);

    // Le lendemain de la date d'effet, le prix programmé est en vigueur.
    $this->travelTo(CarbonImmutable::parse('2026-11-01 00:05', 'Africa/Algiers'));
    $this->getJson('/api/v1/products')
        ->assertJsonPath('data.0.price.price_ht', 950)
        ->assertJsonPath('data.0.next_price.price_ht', 990);
});

it('rounds the TTC and unit prices to the cent (RG-P04)', function () {
    $this->actingAs($this->admin);
    $product = Product::factory()->create(['family_id' => $this->juices->id, 'vat_rate' => '19.00', 'units_per_sales_unit' => 6]);
    ($this->setPrice)($product, '1850.50', '2026-10-01');
    $exempt = Product::factory()->create(['family_id' => $this->juices->id, 'vat_rate' => '0.00', 'units_per_sales_unit' => 24]);
    ($this->setPrice)($exempt, '1000', '2026-10-01');

    // 1 850,50 × 1,19 = 2 202,095 → 2 202,10 ; 1 850,50 ÷ 6 = 308,4166… → 308,42.
    $this->getJson("/api/v1/products/{$product->id}")
        ->assertJsonPath('price.price_ht', 1850.5)
        ->assertJsonPath('price.price_ttc', 2202.1)
        ->assertJsonPath('price.unit_price_ht', 308.42);

    $this->getJson("/api/v1/products/{$exempt->id}")
        ->assertJsonPath('price.price_ttc', 1000)
        ->assertJsonPath('price.unit_price_ht', 41.67);
});

it('has no price before the first effective date, but shows the scheduled one', function () {
    $this->actingAs($this->admin);
    $unpriced = Product::factory()->create(['family_id' => $this->juices->id]);
    $scheduled = Product::factory()->create(['family_id' => $this->juices->id]);
    ($this->setPrice)($scheduled, '700', '2026-10-15');

    $this->getJson("/api/v1/products/{$unpriced->id}")
        ->assertJsonPath('price', null)
        ->assertJsonPath('next_price', null);
    $this->getJson("/api/v1/products/{$scheduled->id}")
        ->assertJsonPath('price', null)
        ->assertJsonPath('next_price', ['price_ht' => 700, 'valid_from' => '2026-10-15']);
});

it('creates a product and journals it', function () {
    $this->actingAs($this->admin);
    $name = 'Jus multivitaminé sans sucres ajoutés, bouteille PET de 1 litre, édition limitée';

    $response = $this->postJson('/api/v1/products', [
        'sku' => ' p0042 ',
        'name' => $name,
        'family_id' => $this->juices->id,
        'format' => '1 L',
        'sales_unit_label' => 'Fardeau 6 × 1 L',
        'units_per_sales_unit' => 6,
        'sales_units_per_pallet' => 80,
        'ean' => 6130000000420,
        'vat_rate' => 19,
        'is_featured' => true,
        'sort_order' => 5,
    ]);

    $response->assertCreated()
        ->assertJsonPath('sku', 'P0042')
        ->assertJsonPath('short_name', rtrim(mb_substr($name, 0, 60)))
        ->assertJsonPath('ean', '6130000000420')
        ->assertJsonPath('is_active', true)
        ->assertJsonPath('is_new', false)
        ->assertJsonPath('is_featured', true)
        ->assertJsonPath('sort_order', 5)
        ->assertJsonPath('price', null)
        ->assertJsonPath('photos', []);

    $product = Product::query()->where('sku', 'P0042')->sole();
    expect(mb_strlen($product->short_name))->toBeLessThanOrEqual(60);

    $log = AuditLog::query()->where('subject_type', 'product')->where('subject_id', $product->id)->sole();
    expect($log->log_name)->toBe('referentials')
        ->and($log->event)->toBe('created')
        ->and($log->description)->toBe('Produit créé')
        ->and($log->causer_id)->toBe($this->admin->id)
        ->and($log->properties['attributes']['sku'])->toBe('P0042');
});

it('validates product creation', function (array $payload, string $field) {
    $this->actingAs($this->admin);
    Product::factory()->create(['sku' => 'P0001', 'family_id' => $this->juices->id]);

    $this->postJson('/api/v1/products', [
        'sku' => 'P0100',
        'name' => 'Jus pomme 1 L',
        'family_id' => $this->juices->id,
        'sales_unit_label' => 'Fardeau 6 × 1 L',
        'units_per_sales_unit' => 6,
        'vat_rate' => 19,
        ...$payload,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonValidationErrors($field);
})->with([
    'duplicate sku (case-insensitive)' => [['sku' => 'p0001'], 'sku'],
    'sku with spaces' => [['sku' => 'P 01'], 'sku'],
    'sku too long' => [['sku' => str_repeat('A', 31)], 'sku'],
    'missing sku' => [['sku' => null], 'sku'],
    'missing name' => [['name' => ''], 'name'],
    'short name too long' => [['short_name' => str_repeat('a', 61)], 'short_name'],
    'unknown family' => [['family_id' => 999], 'family_id'],
    'missing family' => [['family_id' => null], 'family_id'],
    'missing sales unit label' => [['sales_unit_label' => null], 'sales_unit_label'],
    'zero units per sales unit' => [['units_per_sales_unit' => 0], 'units_per_sales_unit'],
    'decimal units per sales unit' => [['units_per_sales_unit' => 1.5], 'units_per_sales_unit'],
    'zero sales units per pallet' => [['sales_units_per_pallet' => 0], 'sales_units_per_pallet'],
    'EAN too short' => [['ean' => '1234567'], 'ean'],
    'EAN with letters' => [['ean' => '61300000004AB'], 'ean'],
    'VAT above 100' => [['vat_rate' => 100.5], 'vat_rate'],
    'negative VAT' => [['vat_rate' => -1], 'vat_rate'],
    'VAT with 3 decimals' => [['vat_rate' => '19.125'], 'vat_rate'],
    'missing VAT' => [['vat_rate' => null], 'vat_rate'],
    'non-boolean flag' => [['is_active' => 'oui'], 'is_active'],
    'negative sort order' => [['sort_order' => -1], 'sort_order'],
]);

it('updates a product partially and journals the changes', function () {
    $this->actingAs($this->admin);
    $product = Product::factory()->create(['sku' => 'P0042', 'name' => 'Jus orange 1 L', 'short_name' => 'Orange 1 L', 'family_id' => $this->juices->id]);

    $this->patchJson("/api/v1/products/{$product->id}", [
        'sku' => 'p0042',
        'name' => 'Jus orange pressée 1 L',
        'is_active' => false,
        'vat_rate' => '9.5',
    ])->assertOk()
        ->assertJsonPath('sku', 'P0042')
        ->assertJsonPath('name', 'Jus orange pressée 1 L')
        ->assertJsonPath('short_name', 'Orange 1 L')
        ->assertJsonPath('vat_rate', 9.5)
        ->assertJsonPath('is_active', false);

    // Désignation courte vidée : reprise de la désignation.
    $this->patchJson("/api/v1/products/{$product->id}", ['short_name' => null])
        ->assertOk()
        ->assertJsonPath('short_name', 'Jus orange pressée 1 L');

    $log = AuditLog::query()->where('subject_type', 'product')->where('subject_id', $product->id)
        ->where('event', 'updated')->orderBy('id')->first();
    expect($log->description)->toBe('Produit modifié')
        ->and($log->causer_id)->toBe($this->admin->id)
        ->and($log->properties['old']['name'])->toBe('Jus orange 1 L')
        ->and($log->properties['attributes']['name'])->toBe('Jus orange pressée 1 L')
        ->and($log->properties['attributes']['is_active'])->toBeFalse();
});

it('keeps the product code immutable', function () {
    $this->actingAs($this->admin);
    $product = Product::factory()->create(['sku' => 'P0042', 'family_id' => $this->juices->id]);

    $this->patchJson("/api/v1/products/{$product->id}", ['sku' => 'P0043'])
        ->assertJsonValidationErrors(['sku' => 'Le code article ne peut pas être modifié']);

    expect($product->fresh()->sku)->toBe('P0042');
});

it('validates product updates', function (array $payload, string $field) {
    $this->actingAs($this->admin);
    $product = Product::factory()->create(['family_id' => $this->juices->id]);

    $this->patchJson("/api/v1/products/{$product->id}", $payload)->assertJsonValidationErrors($field);
})->with([
    'empty name' => [['name' => ''], 'name'],
    'unknown family' => [['family_id' => 999], 'family_id'],
    'null family' => [['family_id' => null], 'family_id'],
    'null units' => [['units_per_sales_unit' => null], 'units_per_sales_unit'],
    'null VAT' => [['vat_rate' => null], 'vat_rate'],
    'null status' => [['is_active' => null], 'is_active'],
]);

it('answers 404 for an unknown product', function () {
    $this->actingAs($this->admin);

    expectProblem($this->getJson('/api/v1/products/999'), 404, 'NOT_FOUND');
    expectProblem($this->patchJson('/api/v1/products/999', ['name' => 'x']), 404, 'NOT_FOUND');
});
