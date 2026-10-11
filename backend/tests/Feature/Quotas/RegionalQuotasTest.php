<?php

use App\Domain\Access\Role;
use App\Domain\Days\CutoffKind;
use App\Domain\Days\DayCalendar;
use App\Domain\Quotas\QuotaBook;
use App\Events\CutoffExtended;
use App\Events\DayClosed;
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
use Illuminate\Support\Facades\Event;

// Quotas du jour du régional : GET /regional/quotas (§7.6, contrat du lot 3 §4).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 07:30', 'Africa/Algiers'));
    Event::fake([QuotasPublished::class, QuotaDraftsSaved::class, CutoffExtended::class, DayClosed::class]);
    $this->adv = userWithRole(Role::Adv);
    $this->regional = userWithRole(Role::Regional);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    $this->sud = Region::factory()->create(['code' => 'SUD', 'name' => 'Sud']);
    // Titulaire du Centre et du Sud ; l'Est appartient à un autre régional.
    RegionAssignment::factory()->create(['user_id' => $this->regional->id, 'region_id' => $this->centre->id]);
    RegionAssignment::factory()->create(['user_id' => $this->regional->id, 'region_id' => $this->sud->id]);
    RegionAssignment::factory()->create(['region_id' => $this->est->id]);

    $this->juices = ProductFamily::factory()->create(['code' => 'JUS', 'name' => 'Jus', 'sort_order' => 10]);
    $this->milk = ProductFamily::factory()->create(['code' => 'LAIT', 'name' => 'Lait', 'sort_order' => 5]);
    $this->orange = Product::factory()->create([
        'sku' => 'P0042', 'name' => 'Jus orange 1 L', 'family_id' => $this->juices->id,
        'sales_unit_label' => 'Fardeau 6 × 1 L', 'sort_order' => 2,
    ]);
    $this->pomme = Product::factory()->create(['sku' => 'P0057', 'family_id' => $this->juices->id, 'sort_order' => 1]);
    $this->lben = Product::factory()->create(['sku' => 'P0100', 'name' => 'Lben 1 L', 'family_id' => $this->milk->id, 'sales_unit_label' => 'Pack 6 × 1 L']);

    $this->calendar = app(DayCalendar::class);
    $this->book = app(QuotaBook::class);
    $this->day = $this->calendar->open(BusinessClock::parseDay('2026-10-08'), $this->adv);
    $this->url = '/api/v1/regional/quotas';
    $this->cell = fn (Region $region, Product $product, ?int $quantity) => ['region_id' => $region->id, 'product_id' => $product->id, 'quantity' => $quantity];
    $this->product = fn (Product $product) => [
        'id' => $product->id, 'sku' => $product->sku, 'name' => $product->name,
        'family' => ['id' => $product->family->id, 'code' => $product->family->code, 'name' => $product->family->name],
        'sales_unit_label' => $product->sales_unit_label,
    ];
});

it('is reserved to the regional role', function (Closure $actor) {
    $this->actingAs($actor());

    // 403 et non 422, même pour une date invalide.
    expectProblem($this->getJson($this->url), 403, 'FORBIDDEN');
    expectProblem($this->getJson($this->url.'?day=demain'), 403, 'FORBIDDEN');
})->with([
    'ADV' => [fn () => userWithRole(Role::Adv)],
    'Admin' => [fn () => admin()],
    'Client' => [fn () => userWithRole(Role::Client)],
    'Sans rôle' => [fn () => User::factory()->create()],
]);

it('validates the day', function (string $query) {
    $this->actingAs($this->regional);

    $this->getJson($this->url.'?'.$query)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonValidationErrors('day');
})->with([
    'French date' => ['day=08/10/2026'],
    'impossible date' => ['day=2026-02-30'],
    'text' => ['day=demain'],
    'list' => ['day[]=2026-10-08'],
]);

it('names an invalid day in French', function () {
    $this->actingAs($this->regional);

    $this->getJson($this->url.'?day=08/10/2026')->assertJsonValidationErrors(['day' => 'Date attendue au format AAAA-MM-JJ.']);
});

it('returns the published quotas of the regions of the perimeter, never the drafts (RG-Q03)', function () {
    $other = Product::factory()->create(['sku' => 'P0099', 'family_id' => $this->juices->id]);
    $this->book->saveDrafts($this->day, [
        ($this->cell)($this->centre, $this->orange, 500),
        ($this->cell)($this->centre, $this->pomme, 200),
        ($this->cell)($this->centre, $this->lben, 100),
        ($this->cell)($this->est, $this->orange, 300),
        ($this->cell)($this->sud, $this->orange, 50),
    ], $this->adv);
    $this->book->publish($this->day, [$this->centre->id, $this->est->id], $this->adv);
    // Version 2 : la pomme retombe à 0 et disparaît de la liste.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 08:10', 'Africa/Algiers'));
    $this->book->saveDrafts($this->day, [($this->cell)($this->centre, $this->pomme, 0)], $this->adv);
    $this->book->publish($this->day, [$this->centre->id], $this->adv);
    // Brouillons en attente : jamais exposés au régional.
    $this->book->saveDrafts($this->day, [
        ($this->cell)($this->centre, $this->orange, 450),
        ($this->cell)($this->centre, $other, 70),
    ], $this->adv);
    RegionQuota::query()->where('product_id', $this->orange->id)->where('quantity', 500)->update(['validated_qty' => 120]);
    $centreDay = RegionDay::query()->where('region_id', $this->centre->id)->sole();
    $this->calendar->extendCutoff($centreDay, CutoffKind::Distribution, '11:15', $this->adv);
    $this->actingAs($this->regional);

    $this->getJson($this->url)->assertOk()->assertExactJson([
        'day' => '2026-10-08',
        'opened' => true,
        'status' => 'OPEN',
        'cutoffs' => ['distribution' => '10:00', 'ordering' => '13:00', 'validation' => '15:00', 'closing' => '23:00'],
        'regions' => [
            [
                'region' => ['id' => $this->centre->id, 'code' => 'CENTRE', 'name' => 'Centre'],
                'status' => 'QUOTA_PUBLISHED',
                'quota_version' => 2,
                'quota_last_published_at' => '2026-10-08T07:10:00+00:00',
                'cutoffs' => ['distribution' => '11:15', 'ordering' => '13:00'],
                // Famille (ordre, puis code), puis ordre et code article, comme la matrice.
                'quotas' => [
                    ['product' => ($this->product)($this->lben), 'quantity' => 100, 'validated' => 0],
                    ['product' => ($this->product)($this->orange), 'quantity' => 500, 'validated' => 120],
                ],
            ],
            [
                // Brouillon de l'ADV non publié : « En attente des quotas ».
                'region' => ['id' => $this->sud->id, 'code' => 'SUD', 'name' => 'Sud'],
                'status' => 'QUOTA_DRAFT',
                'quota_version' => 0,
                'quota_last_published_at' => null,
                'cutoffs' => ['distribution' => '10:00', 'ordering' => '13:00'],
                'quotas' => [],
            ],
        ],
    ]);

    // Même réponse en nommant explicitement la journée.
    $this->getJson($this->url.'?day=2026-10-08')->assertOk()->assertJsonPath('regions.*.region.code', ['CENTRE', 'SUD']);
});

it('answers opened: false for a day that was not opened', function () {
    $this->actingAs($this->regional);

    $this->getJson($this->url.'?day=2026-10-09')->assertOk()->assertExactJson([
        'day' => '2026-10-09',
        'opened' => false,
        'status' => null,
        'cutoffs' => null,
        'regions' => [],
    ]);
});

it('follows the perimeter on the requested day: tomorrow, and a deputy within his period (§3.4)', function () {
    $tomorrow = $this->calendar->open(BusinessClock::parseDay('2026-10-09'), $this->adv);
    // Suppléant de l'Est du 09/10 au 15/10.
    RegionAssignment::factory()->deputy('2026-10-09', '2026-10-15')->create(['user_id' => $this->regional->id, 'region_id' => $this->est->id]);
    $this->book->saveDrafts($tomorrow, [($this->cell)($this->est, $this->orange, 300)], $this->adv);
    $this->book->publish($tomorrow, [$this->est->id], $this->adv);
    $this->actingAs($this->regional);

    $this->getJson($this->url)->assertOk()->assertJsonPath('regions.*.region.code', ['CENTRE', 'SUD']);

    $this->getJson($this->url.'?day=2026-10-09')
        ->assertOk()
        ->assertJsonPath('day', '2026-10-09')
        ->assertJsonPath('regions.*.region.code', ['CENTRE', 'EST', 'SUD'])
        ->assertJsonPath('regions.1.status', 'QUOTA_PUBLISHED')
        ->assertJsonPath('regions.1.quotas', [['product' => ($this->product)($this->orange), 'quantity' => 300, 'validated' => 0]]);

    // Après la fin de la suppléance, l'Est n'est plus dans son périmètre.
    $this->calendar->open(BusinessClock::parseDay('2026-10-18'), $this->adv);
    $this->getJson($this->url.'?day=2026-10-18')->assertOk()->assertJsonPath('regions.*.region.code', ['CENTRE', 'SUD']);
});

it('shows a region created since the opening, and hides an inactive region', function () {
    $ouest = Region::factory()->create(['code' => 'OUEST', 'name' => 'Ouest']);
    RegionAssignment::factory()->create(['user_id' => $this->regional->id, 'region_id' => $ouest->id]);
    $this->sud->update(['is_active' => false]);
    $this->actingAs($this->regional);

    $this->getJson($this->url)
        ->assertOk()
        ->assertJsonPath('regions.*.region.code', ['CENTRE', 'OUEST'])
        ->assertJsonPath('regions.1.status', 'QUOTA_DRAFT')
        ->assertJsonPath('regions.1.quotas', []);

    expect(RegionDay::query()->where('business_day_id', $this->day->id)->where('region_id', $ouest->id)->exists())->toBeTrue();
});

it('keeps the published quotas readable once the day is closed', function () {
    $this->book->saveDrafts($this->day, [($this->cell)($this->centre, $this->orange, 500)], $this->adv);
    $this->book->publish($this->day, [$this->centre->id], $this->adv);
    $this->calendar->close($this->day);
    $this->actingAs($this->regional);

    $this->getJson($this->url)
        ->assertOk()
        ->assertJsonPath('opened', true)
        ->assertJsonPath('status', 'CLOSED')
        ->assertJsonPath('regions.*.status', ['CLOSED', 'CLOSED'])
        ->assertJsonPath('regions.0.quotas.0.quantity', 500);
});

it('returns no region to a regional without any assignment that day', function () {
    $this->actingAs(userWithRole(Role::Regional));

    $this->getJson($this->url)
        ->assertOk()
        ->assertJsonPath('opened', true)
        ->assertJsonPath('regions', []);
});
