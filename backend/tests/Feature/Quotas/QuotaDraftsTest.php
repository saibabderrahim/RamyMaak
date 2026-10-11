<?php

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Domain\Quotas\QuotaBook;
use App\Events\QuotaDraftsSaved;
use App\Events\QuotasPublished;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionDay;
use App\Models\RegionQuota;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// Brouillons de la matrice : PUT /adv/days/{day}/quotas (RG-Q01 à RG-Q03, contrat du lot 3 §4).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 07:30', 'Africa/Algiers'));
    Event::fake([QuotasPublished::class, QuotaDraftsSaved::class]);
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->est = Region::factory()->create(['code' => 'EST']);
    $this->orange = Product::factory()->create(['sku' => 'P0042']);
    $this->pomme = Product::factory()->create(['sku' => 'P0057']);
    $this->day = app(DayCalendar::class)->open(BusinessClock::parseDay('2026-10-08'), $this->adv);
    $this->url = '/api/v1/adv/days/2026-10-08/quotas';
    $this->cell = fn (Region $region, Product $product, mixed $quantity) => ['region_id' => $region->id, 'product_id' => $product->id, 'quantity' => $quantity];
    $this->quota = fn (Region $region, Product $product) => RegionQuota::query()
        ->where('product_id', $product->id)
        ->whereHas('regionDay', fn ($q) => $q->where('business_day_id', $this->day->id)->where('region_id', $region->id))
        ->first();
});

it('requires quota.edit and the back-office before validating the body', function (Closure $actor) {
    $this->actingAs($actor());

    // 403 et non 422, même pour un corps invalide.
    expectProblem($this->putJson($this->url, ['cells' => 'x']), 403, 'FORBIDDEN');
    expectProblem($this->putJson($this->url, ['cells' => [($this->cell)($this->centre, $this->orange, 10)]]), 403, 'FORBIDDEN');
})->with([
    'Admin (lecture seule)' => [fn () => admin()],
    'Régional' => [fn () => userWithRole(Role::Regional)],
    'Régional doté de quota.edit' => [function () {
        $regional = userWithRole(Role::Regional);
        $regional->givePermissionTo(Permission::QuotaEdit->value);

        return $regional;
    }],
    'Client' => [fn () => userWithRole(Role::Client)],
    'Sans rôle' => [fn () => User::factory()->create()],
]);

it('lets an Admin granted quota.edit save drafts', function () {
    $admin = admin();
    $admin->givePermissionTo(Permission::QuotaEdit->value);
    $this->actingAs($admin);

    $this->putJson($this->url, ['cells' => [($this->cell)($this->centre, $this->orange, 10)]])
        ->assertOk()
        ->assertJsonPath('changed', 1);
});

it('saves drafts and returns the state of every cell sent (RG-Q03)', function () {
    $this->actingAs($this->adv);

    $this->putJson($this->url, ['cells' => [
        ($this->cell)($this->centre, $this->orange, 500),
        ($this->cell)($this->est, $this->pomme, 300),
        // 0 sur une cellule sans ligne : rien à enregistrer.
        ($this->cell)($this->centre, $this->pomme, 0),
    ]])->assertOk()->assertExactJson([
        'changed' => 2,
        'cells' => [
            ['region_id' => $this->centre->id, 'product_id' => $this->orange->id, 'quantity' => 0, 'draft' => 500, 'validated' => 0],
            ['region_id' => $this->est->id, 'product_id' => $this->pomme->id, 'quantity' => 0, 'draft' => 300, 'validated' => 0],
            ['region_id' => $this->centre->id, 'product_id' => $this->pomme->id, 'quantity' => 0, 'draft' => null, 'validated' => 0],
        ],
    ]);

    expect(($this->quota)($this->centre, $this->orange)->only(['quantity', 'draft_quantity']))->toBe(['quantity' => 0, 'draft_quantity' => 500])
        ->and(RegionDay::query()->where('region_id', $this->centre->id)->value('quota_version'))->toBe(0);
    Event::assertDispatched(QuotaDraftsSaved::class, fn (QuotaDraftsSaved $e) => $e->businessDayId === $this->day->id
        && $e->regionIds === [$this->centre->id, $this->est->id] && $e->actorId === $this->adv->id);
});

it('cancels a draft with null, and drops a draft equal to the published value', function () {
    $book = app(QuotaBook::class);
    $book->saveDrafts($this->day, [($this->cell)($this->centre, $this->orange, 500)], $this->adv);
    $book->publish($this->day, [$this->centre->id], $this->adv);
    RegionQuota::query()->update(['validated_qty' => 120]);
    $this->actingAs($this->adv);

    $this->putJson($this->url, ['cells' => [($this->cell)($this->centre, $this->orange, 450)]])
        ->assertOk()
        ->assertJsonPath('changed', 1)
        ->assertJsonPath('cells.0', ['region_id' => $this->centre->id, 'product_id' => $this->orange->id, 'quantity' => 500, 'draft' => 450, 'validated' => 120]);

    $this->putJson($this->url, ['cells' => [($this->cell)($this->centre, $this->orange, null)]])
        ->assertOk()
        ->assertJsonPath('changed', 1)
        ->assertJsonPath('cells.0.draft', null);

    $this->putJson($this->url, ['cells' => [($this->cell)($this->centre, $this->orange, 600)]])->assertJsonPath('cells.0.draft', 600);
    $this->putJson($this->url, ['cells' => [($this->cell)($this->centre, $this->orange, 500)]])
        ->assertOk()
        ->assertJsonPath('changed', 1)
        ->assertJsonPath('cells.0.draft', null);

    // Rien ne change : aucun événement de plus.
    Event::fake([QuotaDraftsSaved::class]);
    $this->putJson($this->url, ['cells' => [($this->cell)($this->centre, $this->orange, null)]])->assertJsonPath('changed', 0);
    Event::assertNotDispatched(QuotaDraftsSaved::class);
});

it('validates the cells', function (Closure $payload, string $field) {
    $this->actingAs($this->adv);

    $this->putJson($this->url, $payload->call($this))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonValidationErrors($field);

    expect(RegionQuota::query()->count())->toBe(0);
})->with([
    'no cells' => [fn () => [], 'cells'],
    'empty list' => [fn () => ['cells' => []], 'cells'],
    'not a list' => [fn () => ['cells' => ['a' => ($this->cell)($this->centre, $this->orange, 1)]], 'cells'],
    'cell not an object' => [fn () => ['cells' => [5]], 'cells.0'],
    'negative quantity' => [fn () => ['cells' => [($this->cell)($this->centre, $this->orange, -1)]], 'cells.0.quantity'],
    'quantity above 1 000 000' => [fn () => ['cells' => [($this->cell)($this->centre, $this->orange, 1_000_001)]], 'cells.0.quantity'],
    'decimal quantity' => [fn () => ['cells' => [($this->cell)($this->centre, $this->orange, 12.5)]], 'cells.0.quantity'],
    'quantity as text' => [fn () => ['cells' => [($this->cell)($this->centre, $this->orange, '12')]], 'cells.0.quantity'],
    'boolean quantity' => [fn () => ['cells' => [($this->cell)($this->centre, $this->orange, true)]], 'cells.0.quantity'],
    'missing quantity' => [fn () => ['cells' => [['region_id' => $this->centre->id, 'product_id' => $this->orange->id]]], 'cells.0.quantity'],
    'missing region' => [fn () => ['cells' => [['product_id' => $this->orange->id, 'quantity' => 1]]], 'cells.0.region_id'],
    'region as text' => [fn () => ['cells' => [['region_id' => 'CENTRE', 'product_id' => $this->orange->id, 'quantity' => 1]]], 'cells.0.region_id'],
    'decimal product' => [fn () => ['cells' => [['region_id' => $this->centre->id, 'product_id' => 1.5, 'quantity' => 1]]], 'cells.0.product_id'],
    'unknown product' => [fn () => ['cells' => [['region_id' => $this->centre->id, 'product_id' => 99999, 'quantity' => 1]]], 'cells.0.product_id'],
    'unknown region' => [fn () => ['cells' => [['region_id' => 99999, 'product_id' => $this->orange->id, 'quantity' => 1]]], 'cells.0.region_id'],
    'same cell twice' => [fn () => ['cells' => [
        ($this->cell)($this->centre, $this->orange, 1),
        ($this->cell)($this->est, $this->orange, 2),
        ($this->cell)($this->centre, $this->orange, 3),
    ]], 'cells.2.product_id'],
]);

it('names the faulty value in French', function () {
    $this->actingAs($this->adv);

    $this->putJson($this->url, ['cells' => [
        ($this->cell)($this->centre, $this->orange, 12.5),
        ($this->cell)($this->centre, $this->orange, 3),
    ]])->assertJsonValidationErrors([
        'cells.0.quantity' => 'La quantité doit être un nombre entier de 0 à 1 000 000, ou vide pour annuler le brouillon.',
        'cells.1.product_id' => 'Cette région et ce produit figurent déjà dans la requête (élément 0).',
    ]);
});

it('refuses inactive products and inactive regions (RG-Q02)', function () {
    $inactiveProduct = Product::factory()->inactive()->create();
    $inactiveRegion = Region::factory()->inactive()->create();
    $this->actingAs($this->adv);

    $this->putJson($this->url, ['cells' => [
        ($this->cell)($this->centre, $this->orange, 10),
        ($this->cell)($this->centre, $inactiveProduct, 10),
        ($this->cell)($inactiveRegion, $this->orange, 10),
    ]])->assertUnprocessable()->assertJsonValidationErrors([
        'cells.1.product_id' => 'Produit inconnu ou inactif : il ne peut pas recevoir de quota.',
        'cells.2.region_id' => 'Région inconnue ou inactive.',
    ]);

    // Tout ou rien : la cellule valide n'est pas enregistrée non plus.
    expect(RegionQuota::query()->count())->toBe(0);
});

it('refuses a cell of a region outside the perimeter of a restricted ADV (RG-S05)', function () {
    $restricted = userWithRole(Role::Adv);
    RegionAssignment::factory()->adv()->create(['user_id' => $restricted->id, 'region_id' => $this->est->id]);
    $this->actingAs($restricted);

    $this->putJson($this->url, ['cells' => [
        ($this->cell)($this->est, $this->orange, 10),
        ($this->cell)($this->centre, $this->orange, 10),
    ]])->assertUnprocessable()
        ->assertJsonValidationErrors(['cells.1.region_id' => 'Région hors de votre périmètre.']);

    $this->putJson($this->url, ['cells' => [($this->cell)($this->est, $this->orange, 10)]])
        ->assertOk()
        ->assertJsonPath('changed', 1);
});

it('accepts up to 2 000 cells, not one more', function () {
    $family = ProductFamily::factory()->create();
    $rows = [];
    foreach (range(1, 1000) as $n) {
        $rows[] = [
            'sku' => sprintf('Q%05d', $n), 'name' => "Produit {$n}", 'short_name' => "Produit {$n}", 'family_id' => $family->id,
            'sales_unit_label' => 'Fardeau', 'units_per_sales_unit' => 6, 'vat_rate' => '19.00', 'is_active' => true,
            'sort_order' => $n, 'created_at' => now(), 'updated_at' => now(),
        ];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('products')->insert($chunk);
    }
    $cells = [];
    foreach (Product::query()->where('sku', 'like', 'Q%')->pluck('id') as $productId) {
        foreach ([$this->centre, $this->est] as $region) {
            $cells[] = ['region_id' => $region->id, 'product_id' => $productId, 'quantity' => 7];
        }
    }
    $this->actingAs($this->adv);

    $this->putJson($this->url, ['cells' => $cells])
        ->assertOk()
        ->assertJsonPath('changed', 2000)
        ->assertJsonCount(2000, 'cells');

    $cells[] = ($this->cell)($this->centre, $this->orange, 1);
    $this->putJson($this->url, ['cells' => $cells])->assertJsonValidationErrors('cells');
});

it('refuses any change once the day is closed', function () {
    app(DayCalendar::class)->close($this->day);
    $this->actingAs($this->adv);

    expectProblem($this->putJson($this->url, ['cells' => [($this->cell)($this->centre, $this->orange, 10)]]), 409, 'DAY_NOT_OPEN');
    expect(RegionQuota::query()->count())->toBe(0);
});

it('answers 404 for a day that was not opened', function () {
    $this->actingAs($this->adv);

    expectProblem($this->putJson('/api/v1/adv/days/2026-10-09/quotas', ['cells' => [($this->cell)($this->centre, $this->orange, 10)]]), 404, 'NOT_FOUND');
});
