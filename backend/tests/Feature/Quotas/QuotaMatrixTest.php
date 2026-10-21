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
use App\Models\RegionQuota;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// Matrice des quotas : GET /adv/days/{day}/quotas (EF-ADV-02, contrat du lot 3 §4).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 07:30', 'Africa/Algiers'));
    Event::fake([QuotasPublished::class, QuotaDraftsSaved::class]);
    $this->adv = userWithRole(Role::Adv);
    // Créées dans le désordre : les colonnes sont triées par code.
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->juices = ProductFamily::factory()->create(['code' => 'JUS', 'name' => 'Jus', 'sort_order' => 10]);
    $this->orange = Product::factory()->create([
        'sku' => 'P0042', 'name' => 'Jus orange 1 L', 'short_name' => 'Orange 1 L',
        'family_id' => $this->juices->id, 'sales_unit_label' => 'Fardeau 6 × 1 L', 'sort_order' => 2,
    ]);
    $this->pomme = Product::factory()->create(['sku' => 'P0057', 'family_id' => $this->juices->id, 'sort_order' => 1]);
    $this->day = app(DayCalendar::class)->open(BusinessClock::parseDay('2026-10-08'), $this->adv);
    $this->book = app(QuotaBook::class);
    $this->url = '/api/v1/adv/days/2026-10-08/quotas';
    $this->cell = fn (Region $region, Product $product, ?int $quantity) => ['region_id' => $region->id, 'product_id' => $product->id, 'quantity' => $quantity];
});

it('reserves the matrix to the back-office with quota.view', function (Role $role, int $status) {
    $this->actingAs(userWithRole($role));

    $response = $this->getJson($this->url);
    $status === 200 ? $response->assertOk() : expectProblem($response, $status, 'FORBIDDEN');
})->with([
    'ADV' => [Role::Adv, 200],
    'Admin (lecture)' => [Role::Admin, 200],
    // Le régional a quota.view, mais lit les quotas publiés par /regional/quotas.
    'Régional' => [Role::Regional, 403],
    'Client' => [Role::Client, 403],
]);

it('refuses the matrix to an account without role or without quota.view', function () {
    $this->actingAs(User::factory()->create());
    expectProblem($this->getJson($this->url), 403, 'FORBIDDEN');

    $regional = userWithRole(Role::Regional);
    $regional->givePermissionTo(Permission::QuotaEdit->value);
    $this->actingAs($regional);
    expectProblem($this->getJson($this->url), 403, 'FORBIDDEN');
});

it('answers 404 for a day that was not opened or a malformed date', function () {
    $this->actingAs($this->adv);

    expectProblem($this->getJson('/api/v1/adv/days/2026-10-09/quotas'), 404, 'NOT_FOUND');
    expectProblem($this->getJson('/api/v1/adv/days/2026-02-30/quotas'), 404, 'NOT_FOUND');
    expectProblem($this->getJson('/api/v1/adv/days/08-10-2026/quotas'), 404, 'NOT_FOUND');
});

it('returns the day, the region columns, the product rows and the existing cells', function () {
    $milk = ProductFamily::factory()->create(['code' => 'LAIT', 'name' => 'Lait', 'sort_order' => 5]);
    $drinks = ProductFamily::factory()->create(['code' => 'BOISSON', 'name' => 'Boissons', 'sort_order' => 10]);
    $lben = Product::factory()->create(['sku' => 'P0100', 'family_id' => $milk->id, 'sort_order' => 9]);
    $soda = Product::factory()->create(['sku' => 'P0200', 'family_id' => $drinks->id, 'sort_order' => 1]);
    $cocktail = Product::factory()->create(['sku' => 'P0010', 'family_id' => $this->juices->id, 'sort_order' => 1]);
    $raisin = Product::factory()->create(['sku' => 'P0300', 'family_id' => $this->juices->id, 'sort_order' => 3]);
    $fraise = Product::factory()->create(['sku' => 'P0400', 'family_id' => $this->juices->id, 'sort_order' => 4]);
    $mangue = Product::factory()->create(['sku' => 'P0500', 'family_id' => $this->juices->id, 'sort_order' => 5]);
    Product::factory()->inactive()->create(['sku' => 'P0600', 'family_id' => $this->juices->id]);
    $ouest = Region::factory()->create(['code' => 'OUEST']);

    $this->book->saveDrafts($this->day, [
        ($this->cell)($this->centre, $this->orange, 500),
        ($this->cell)($this->centre, $raisin, 120),
        ($this->cell)($ouest, $this->orange, 50),
        ($this->cell)($ouest, $mangue, 80),
    ], $this->adv);
    $this->book->publish($this->day, [$this->centre->id, $ouest->id], $this->adv);
    $this->book->saveDrafts($this->day, [
        ($this->cell)($this->centre, $this->orange, 450),
        ($this->cell)($this->est, $this->pomme, 300),
        ($this->cell)($this->centre, $fraise, 40),
    ], $this->adv);
    RegionQuota::query()->where('product_id', $this->orange->id)->where('quantity', 500)->update(['validated_qty' => 100]);
    // Désactivés depuis : le raisin garde un quota publié au Centre, la mangue
    // seulement dans une région désactivée ; la fraise n'a qu'un brouillon.
    Product::query()->whereKey([$raisin->id, $mangue->id, $fraise->id])->update(['is_active' => false]);
    $ouest->update(['is_active' => false]);
    $this->actingAs($this->adv);

    $response = $this->getJson($this->url)->assertOk();

    $response->assertJsonPath('day.day', '2026-10-08')
        ->assertJsonPath('day.status', 'OPEN')
        ->assertJsonPath('day.regions.*.region.code', ['CENTRE', 'EST'])
        // Le brouillon de la fraise, désactivée depuis, ne sera jamais publié : il ne compte pas (RG-Q02).
        ->assertJsonPath('regions', [
            ['id' => $this->centre->id, 'code' => 'CENTRE', 'name' => 'Centre', 'status' => 'QUOTA_PUBLISHED', 'quota_version' => 1, 'pending_drafts' => 1],
            ['id' => $this->est->id, 'code' => 'EST', 'name' => 'Est', 'status' => 'QUOTA_DRAFT', 'quota_version' => 0, 'pending_drafts' => 1],
        ])
        // Famille (ordre, puis code), puis ordre et code article du produit.
        ->assertJsonPath('products.*.sku', ['P0100', 'P0200', 'P0010', 'P0057', 'P0042', 'P0300'])
        ->assertJsonPath('products.*.is_active', [true, true, true, true, true, false])
        ->assertJsonPath('products.4', [
            'id' => $this->orange->id, 'sku' => 'P0042', 'name' => 'Jus orange 1 L', 'short_name' => 'Orange 1 L',
            'family' => ['id' => $this->juices->id, 'code' => 'JUS', 'name' => 'Jus'],
            'sales_unit_label' => 'Fardeau 6 × 1 L', 'is_active' => true,
        ]);

    expect($response->json('cells'))->toEqual([
        $this->orange->id => [$this->centre->id => ['quantity' => 500, 'draft' => 450, 'validated' => 100]],
        $raisin->id => [$this->centre->id => ['quantity' => 120, 'draft' => null, 'validated' => 0]],
        $this->pomme->id => [$this->est->id => ['quantity' => 0, 'draft' => 300, 'validated' => 0]],
    ]);
    // Objets JSON indexés par identifiant, dans l'ordre des lignes puis des colonnes.
    expect($response->getContent())->toContain('"cells":{'
        .'"'.$this->pomme->id.'":{"'.$this->est->id.'":{"quantity":0,"draft":300,"validated":0}},'
        .'"'.$this->orange->id.'":{"'.$this->centre->id.'":{"quantity":500,"draft":450,"validated":100}},'
        .'"'.$raisin->id.'":{"'.$this->centre->id.'":{"quantity":120,"draft":null,"validated":0}}}')
        ->and($response->json('products.0.id'))->toBe($lben->id)
        ->and($response->json('products.1.id'))->toBe($soda->id)
        ->and($response->json('products.2.id'))->toBe($cocktail->id);
});

it('serializes an empty matrix with cells as a JSON object', function () {
    $this->actingAs($this->adv);

    $response = $this->getJson($this->url)->assertOk();

    expect($response->getContent())->toContain('"cells":{}')
        ->and($response->json('products.*.sku'))->toBe(['P0057', 'P0042']);
});

it('limits the columns and the cells to the regions of a restricted ADV (RG-S05)', function () {
    $this->book->saveDrafts($this->day, [
        ($this->cell)($this->centre, $this->orange, 500),
        ($this->cell)($this->est, $this->orange, 300),
    ], $this->adv);
    $restricted = userWithRole(Role::Adv);
    RegionAssignment::factory()->adv()->create(['user_id' => $restricted->id, 'region_id' => $this->est->id]);
    $this->actingAs($restricted);

    $response = $this->getJson($this->url)->assertOk()
        ->assertJsonPath('regions.*.code', ['EST'])
        ->assertJsonPath('day.regions.*.region.code', ['EST']);

    expect($response->json('cells'))->toEqual([$this->orange->id => [$this->est->id => ['quantity' => 0, 'draft' => 300, 'validated' => 0]]]);
});

it('shows a region created since the opening as a new column, and stays readable once the day is closed', function () {
    $sud = Region::factory()->create(['code' => 'SUD']);
    $this->actingAs($this->adv);

    $this->getJson($this->url)->assertOk()->assertJsonPath('regions.*.code', ['CENTRE', 'EST', 'SUD'])
        ->assertJsonPath('regions.2.status', 'QUOTA_DRAFT');
    expect($this->day->regionDays()->where('region_id', $sud->id)->exists())->toBeTrue();

    app(DayCalendar::class)->close($this->day);
    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('day.status', 'CLOSED')
        ->assertJsonPath('regions.*.status', ['CLOSED', 'CLOSED', 'CLOSED']);
});

it('loads the matrix with a bounded number of queries', function () {
    $this->actingAs($this->adv);
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($this->url)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $this->book->saveDrafts($this->day, [($this->cell)($this->centre, $this->orange, 10)], $this->adv);
    $queries(); // Réchauffe les caches (permissions, paramètres).
    $few = $queries();

    // 20 produits × 4 régions, toutes les cellules renseignées.
    $products = Product::factory()->count(18)->create(['family_id' => ProductFamily::factory()]);
    $regions = Region::factory()->count(2)->create();
    $cells = [];
    foreach ([$this->centre, $this->est, ...$regions] as $region) {
        foreach ([$this->orange, $this->pomme, ...$products] as $product) {
            $cells[] = ($this->cell)($region, $product, 100);
        }
    }
    $this->book->saveDrafts($this->day, $cells, $this->adv);
    $queries(); // Journées régionales des deux nouvelles régions.

    $many = $queries();
    $response = $this->getJson($this->url);
    expect($many)->toBe($few)
        ->and($response->json('products'))->toHaveCount(20)
        ->and($response->json('regions'))->toHaveCount(4)
        ->and(collect($response->json('cells'))->flatten(1))->toHaveCount(80);
});
