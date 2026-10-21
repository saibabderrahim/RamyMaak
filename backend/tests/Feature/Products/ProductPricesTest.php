<?php

use App\Domain\Access\Role;
use App\Domain\Referentials\PriceBook;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00', 'Africa/Algiers'));
    $this->admin = admin(['username' => 'admin.prix', 'name' => 'Admin Prix']);
    $this->product = Product::factory()->create(['sku' => 'P0042', 'vat_rate' => '19.00', 'units_per_sales_unit' => 6]);
});

it('lists the price history, newest first, to the staff', function (Role $role) {
    $book = app(PriceBook::class);
    $book->setPrice($this->product, '900', BusinessClock::parseDay('2026-09-01'), $this->admin);
    $book->setPrice($this->product, '950', BusinessClock::parseDay('2026-11-01'), null);
    $this->actingAs(userWithRole($role));

    $this->getJson("/api/v1/products/{$this->product->id}/prices")
        ->assertOk()
        ->assertJsonPath('data.*.valid_from', ['2026-11-01', '2026-09-01'])
        ->assertJsonPath('data.0.price_ht', 950)
        ->assertJsonPath('data.0.valid_to', null)
        ->assertJsonPath('data.0.created_by', null)
        ->assertJsonPath('data.1.valid_to', '2026-10-31')
        ->assertJsonPath('data.1.created_by', ['id' => $this->admin->id, 'username' => 'admin.prix', 'name' => 'Admin Prix'])
        ->assertJsonStructure(['data' => [['id', 'price_ht', 'valid_from', 'valid_to', 'created_by', 'created_at']]]);
})->with([Role::Admin, Role::Adv, Role::Regional]);

it('hides the price history from client accounts', function () {
    $this->actingAs(userWithRole(Role::Client));

    expectProblem($this->getJson("/api/v1/products/{$this->product->id}/prices"), 403, 'FORBIDDEN');
});

it('reserves price entry to product.manage', function (Role $role) {
    $this->actingAs(userWithRole($role));

    // Droits contrôlés avant la validation : 403 même pour un corps vide.
    expectProblem($this->postJson("/api/v1/products/{$this->product->id}/prices", []), 403, 'FORBIDDEN');
    expect(ProductPrice::query()->count())->toBe(0);
})->with([Role::Adv, Role::Regional, Role::Client]);

it('records a price through the price book and journals it', function () {
    $this->actingAs($this->admin);

    $this->postJson("/api/v1/products/{$this->product->id}/prices", ['price_ht' => 900, 'valid_from' => '2026-10-08'])
        ->assertCreated()
        ->assertJsonPath('price_ht', 900)
        ->assertJsonPath('valid_from', '2026-10-08')
        ->assertJsonPath('valid_to', null)
        ->assertJsonPath('created_by.id', $this->admin->id);

    $this->getJson("/api/v1/products/{$this->product->id}")
        ->assertJsonPath('price', ['price_ht' => 900, 'price_ttc' => 1071, 'unit_price_ht' => 150, 'valid_from' => '2026-10-08']);

    $price = ProductPrice::query()->sole();
    $log = AuditLog::query()->where('subject_type', 'product_price')->where('subject_id', $price->id)->sole();
    expect($log->log_name)->toBe('referentials')
        ->and($log->description)->toBe('Prix créé')
        ->and($log->causer_id)->toBe($this->admin->id)
        ->and($log->properties['attributes']['price_ht'])->toBe('900.00');
});

it('schedules a future price and recomputes the validity periods', function () {
    $this->actingAs($this->admin);
    $this->postJson("/api/v1/products/{$this->product->id}/prices", ['price_ht' => '900.00', 'valid_from' => '2026-10-08'])->assertCreated();

    $this->postJson("/api/v1/products/{$this->product->id}/prices", ['price_ht' => '950.5', 'valid_from' => '2026-11-01'])
        ->assertCreated()
        ->assertJsonPath('price_ht', 950.5);

    $this->getJson("/api/v1/products/{$this->product->id}")
        ->assertJsonPath('price.price_ht', 900)
        ->assertJsonPath('next_price', ['price_ht' => 950.5, 'valid_from' => '2026-11-01']);

    $this->getJson("/api/v1/products/{$this->product->id}/prices")
        ->assertJsonPath('data.*.valid_from', ['2026-11-01', '2026-10-08'])
        ->assertJsonPath('data.1.valid_to', '2026-10-31');
});

it('refuses a past effective date: a price already applied is never rewritten (RG-P03)', function () {
    app(PriceBook::class)->setPrice($this->product, '900', BusinessClock::parseDay('2026-10-01'), $this->admin);
    $this->actingAs($this->admin);

    foreach (['2026-10-01', '2026-10-07'] as $day) {
        $this->postJson("/api/v1/products/{$this->product->id}/prices", ['price_ht' => 950, 'valid_from' => $day])
            ->assertUnprocessable()
            ->assertJsonPath('errors.valid_from.0', 'La date d\'effet ne peut pas être passée : un prix déjà appliqué ne se modifie pas.');
    }
    expect(ProductPrice::query()->sole()->price_ht)->toBe('900.00');

    $this->postJson("/api/v1/products/{$this->product->id}/prices", ['price_ht' => 950, 'valid_from' => '2026-10-08'])->assertCreated();
});

it('credits the replacement of a scheduled price to its new author', function () {
    $this->actingAs($this->admin);
    $this->postJson("/api/v1/products/{$this->product->id}/prices", ['price_ht' => 900, 'valid_from' => '2026-11-01'])->assertCreated();

    $other = admin(['username' => 'admin.bis', 'name' => 'Admin Bis']);
    $this->actingAs($other);
    $this->postJson("/api/v1/products/{$this->product->id}/prices", ['price_ht' => 920, 'valid_from' => '2026-11-01'])
        ->assertCreated()
        ->assertJsonPath('created_by.id', $other->id);
});

it('replaces the price of an existing effective date', function () {
    $this->actingAs($this->admin);
    $this->postJson("/api/v1/products/{$this->product->id}/prices", ['price_ht' => 900, 'valid_from' => '2026-11-01'])->assertCreated();

    $this->postJson("/api/v1/products/{$this->product->id}/prices", ['price_ht' => 920.5, 'valid_from' => '2026-11-01'])
        ->assertCreated()
        ->assertJsonPath('price_ht', 920.5);

    $price = ProductPrice::query()->sole();
    expect($price->price_ht)->toBe('920.50');

    $log = AuditLog::query()->where('subject_type', 'product_price')->where('event', 'updated')->sole();
    expect($log->description)->toBe('Prix modifié')
        ->and($log->properties['old']['price_ht'])->toBe('900.00')
        ->and($log->properties['attributes']['price_ht'])->toBe('920.50');
});

it('validates a new price', function (array $payload, string $field) {
    $this->actingAs($this->admin);

    $this->postJson("/api/v1/products/{$this->product->id}/prices", ['price_ht' => 900, 'valid_from' => '2026-11-01', ...$payload])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonValidationErrors($field);

    expect(ProductPrice::query()->count())->toBe(0);
})->with([
    'missing price' => [['price_ht' => null], 'price_ht'],
    'negative price' => [['price_ht' => -1], 'price_ht'],
    'three decimals' => [['price_ht' => '900.125'], 'price_ht'],
    'float with three decimals' => [['price_ht' => 900.125], 'price_ht'],
    'text' => [['price_ht' => 'neuf cents'], 'price_ht'],
    'leading dot' => [['price_ht' => '.5'], 'price_ht'],
    'explicit sign' => [['price_ht' => '+900'], 'price_ht'],
    'exponent' => [['price_ht' => '9e2'], 'price_ht'],
    'too large' => [['price_ht' => '100000000000'], 'price_ht'],
    'missing date' => [['valid_from' => null], 'valid_from'],
    'French date' => [['valid_from' => '01/11/2026'], 'valid_from'],
    'impossible date' => [['valid_from' => '2026-02-30'], 'valid_from'],
]);

it('answers 404 for the prices of an unknown product', function () {
    $this->actingAs($this->admin);

    expectProblem($this->getJson('/api/v1/products/999/prices'), 404, 'NOT_FOUND');
    expectProblem($this->postJson('/api/v1/products/999/prices', ['price_ht' => 1, 'valid_from' => '2026-11-01']), 404, 'NOT_FOUND');
});
