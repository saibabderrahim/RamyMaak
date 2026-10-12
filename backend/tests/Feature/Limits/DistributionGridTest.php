<?php

use App\Domain\Access\Role;
use App\Domain\Days\CutoffKind;
use App\Domain\Days\DayCalendar;
use App\Domain\Limits\ClientUsage;
use App\Domain\Limits\LimitBook;
use App\Domain\Quotas\QuotaBook;
use App\Domain\Settings\Settings;
use App\Events\CutoffExtended;
use App\Events\DayClosed;
use App\Events\DistributionPublished;
use App\Events\LimitDraftsSaved;
use App\Events\QuotaDraftsSaved;
use App\Events\QuotasPublished;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionDay;
use App\Models\RegionQuota;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// Grille de répartition : GET /regional/days/{day}/grid?region= et
// GET /adv/days/{day}/regions/{region}/grid (EF-REG-02 ; contrat du lot 4, §3).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 08:30', 'Africa/Algiers'));
    Event::fake([QuotasPublished::class, QuotaDraftsSaved::class, LimitDraftsSaved::class, DistributionPublished::class, CutoffExtended::class, DayClosed::class]);
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    $this->regional = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['user_id' => $this->regional->id, 'region_id' => $this->centre->id]);

    $this->wholesale = ClientCategory::factory()->create(['code' => 'GROS', 'name' => 'Grossiste']);
    // Créés dans le désordre : les lignes sont triées par code.
    $this->b = Client::factory()->create(['code' => 'C0047', 'name' => 'Client B', 'region_id' => $this->centre->id]);
    $this->a = Client::factory()->create(['code' => 'C0012', 'name' => 'Client A', 'region_id' => $this->centre->id, 'category_id' => $this->wholesale->id]);
    $this->blocked = Client::factory()->blocked()->create(['code' => 'C0050', 'name' => 'Client bloqué', 'region_id' => $this->centre->id]);
    Client::factory()->inactive()->create(['code' => 'C0060', 'region_id' => $this->centre->id]);
    $this->elsewhere = Client::factory()->create(['code' => 'C0099', 'region_id' => $this->est->id]);

    $this->juices = ProductFamily::factory()->create(['code' => 'JUS', 'name' => 'Jus', 'sort_order' => 10]);
    $this->milk = ProductFamily::factory()->create(['code' => 'LAIT', 'name' => 'Lait', 'sort_order' => 5]);
    $this->orange = Product::factory()->create([
        'sku' => 'P0042', 'name' => 'Jus orange 1 L', 'short_name' => 'Orange 1 L',
        'family_id' => $this->juices->id, 'sales_unit_label' => 'Fardeau 6 × 1 L', 'sort_order' => 2,
    ]);
    $this->pomme = Product::factory()->create(['sku' => 'P0057', 'family_id' => $this->juices->id, 'sort_order' => 1]);
    $this->lben = Product::factory()->create(['sku' => 'P0103', 'family_id' => $this->milk->id, 'sort_order' => 1]);
    $this->fraise = Product::factory()->create(['sku' => 'P0080', 'family_id' => $this->juices->id, 'sort_order' => 3]);
    $this->mangue = Product::factory()->create(['sku' => 'P0090', 'family_id' => $this->juices->id, 'sort_order' => 4]);

    $this->calendar = app(DayCalendar::class);
    $this->quotas = app(QuotaBook::class);
    $this->limits = app(LimitBook::class);
    $this->day = $this->calendar->open(BusinessClock::parseDay('2026-10-08'), $this->adv);
    $this->publishQuotas = function (Region $region, array $quantities) {
        $cells = [];
        foreach ($quantities as [$product, $quantity]) {
            $cells[] = ['region_id' => $region->id, 'product_id' => $product->id, 'quantity' => $quantity];
        }
        $this->quotas->saveDrafts($this->day, $cells, $this->adv);
        $this->quotas->publish($this->day, [$region->id], $this->adv);
    };
    $this->regionDay = fn (Region $region) => RegionDay::query()->where('business_day_id', $this->day->id)->where('region_id', $region->id)->sole();
    $this->cell = fn (Client $client, Product $product, ?int $limit) => ['client_id' => $client->id, 'product_id' => $product->id, 'limit' => $limit];
    $this->url = "/api/v1/regional/days/2026-10-08/grid?region={$this->centre->id}";
    $this->product = fn (Product $product, int $quota, int $sum, int $pending, int $validated) => [
        'id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'short_name' => $product->short_name,
        'family' => ['id' => $product->family->id, 'code' => $product->family->code, 'name' => $product->family->name],
        'sales_unit_label' => $product->sales_unit_label,
        'quota' => $quota, 'sum_limits' => $sum, 'pending' => $pending, 'validated' => $validated, 'remaining' => $quota - $validated,
    ];
    $this->clients = [
        ['id' => $this->a->id, 'code' => 'C0012', 'name' => 'Client A', 'status' => 'ACTIVE', 'category' => ['id' => $this->wholesale->id, 'code' => 'GROS', 'name' => 'Grossiste']],
        ['id' => $this->b->id, 'code' => 'C0047', 'name' => 'Client B', 'status' => 'ACTIVE', 'category' => null],
        ['id' => $this->blocked->id, 'code' => 'C0050', 'name' => 'Client bloqué', 'status' => 'BLOCKED', 'category' => null],
    ];
});

it('returns the day, the region, the product columns, the client rows, the filled cells and the sending summary', function () {
    // Quotas : la fraise à 0 et la mangue désactivée depuis ne sont pas disponibles (RG-B02).
    ($this->publishQuotas)($this->centre, [[$this->orange, 500], [$this->pomme, 300], [$this->lben, 800], [$this->fraise, 0], [$this->mangue, 100]]);
    ($this->publishQuotas)($this->est, [[$this->orange, 200]]);
    $centre = ($this->regionDay)($this->centre);
    $this->limits->saveDrafts($centre, [
        ($this->cell)($this->a, $this->orange, 50),
        ($this->cell)($this->b, $this->orange, 100),
        ($this->cell)($this->blocked, $this->lben, 0),
        ($this->cell)($this->blocked, $this->pomme, 290),
        ($this->cell)($this->a, $this->mangue, 30),
    ], $this->regional);
    $this->travelTo(CarbonImmutable::parse('2026-10-08 08:40', 'Africa/Algiers'));
    $this->limits->publish($centre, $this->regional);
    // Brouillons : une borne modifiée, une borne retirée, une nouvelle borne ;
    // un brouillon annulé (revenu à « pas de borne ») ne laisse aucune cellule.
    $this->limits->saveDrafts($centre, [
        ($this->cell)($this->a, $this->orange, 60),
        ($this->cell)($this->b, $this->orange, null),
        ($this->cell)($this->b, $this->pomme, 20),
        ($this->cell)($this->a, $this->pomme, 10),
    ], $this->regional);
    $this->limits->saveDrafts($centre, [($this->cell)($this->a, $this->pomme, null)], $this->regional);
    $this->mangue->update(['is_active' => false]);
    RegionQuota::query()->where('region_day_id', $centre->id)->where('product_id', $this->orange->id)->update(['validated_qty' => 300]);
    $this->calendar->extendCutoff($centre, CutoffKind::Distribution, '11:15', $this->adv);
    $this->actingAs($this->regional);

    $response = $this->getJson($this->url)->assertOk()->assertExactJson([
        'day' => [
            'day' => '2026-10-08', 'status' => 'OPEN', 'delivery_date' => '2026-10-11',
            'cutoffs' => ['distribution' => '10:00', 'ordering' => '13:00', 'validation' => '15:00', 'closing' => '23:00'],
        ],
        'region' => [
            'id' => $this->centre->id, 'code' => 'CENTRE', 'name' => 'Centre',
            'status' => 'DISTRIBUTION_PUBLISHED', 'status_label' => 'Répartition publiée',
            'quota_version' => 1, 'distribution_version' => 1,
            'distribution_published_at' => '2026-10-08T07:40:00+00:00',
            // Heures applicables à la région, prolongation comprise (RG-A05).
            'cutoffs' => ['distribution' => '11:15', 'ordering' => '13:00'],
            'extended' => ['distribution' => true, 'ordering' => false],
        ],
        'policy' => 'FREE',
        'can_edit' => true,
        'can_publish' => true,
        // Famille (ordre, puis code), puis ordre et code article, comme la matrice des quotas.
        'products' => [
            ($this->product)($this->lben, 800, 0, 0, 0),
            ($this->product)($this->pomme, 300, 310, 0, 0),
            ($this->product)($this->orange, 500, 60, 0, 300),
        ],
        // Clients de la région, actifs et bloqués, par code ; ni l'inactif ni celui de l'Est.
        'clients' => $this->clients,
        'cells' => [
            $this->a->id => [
                $this->orange->id => ['limit' => 50, 'draft' => ['value' => 60], 'pending' => 0, 'validated' => 0],
            ],
            $this->b->id => [
                $this->pomme->id => ['limit' => null, 'draft' => ['value' => 20], 'pending' => 0, 'validated' => 0],
                $this->orange->id => ['limit' => 100, 'draft' => ['value' => null], 'pending' => 0, 'validated' => 0],
            ],
            $this->blocked->id => [
                $this->lben->id => ['limit' => 0, 'draft' => null, 'pending' => 0, 'validated' => 0],
                $this->pomme->id => ['limit' => 290, 'draft' => null, 'pending' => 0, 'validated' => 0],
            ],
        ],
        // Envoi suivant : trois brouillons, deux clients actifs impactés, la pomme en surallocation (RG-B05).
        'publication' => [
            'first' => false, 'drafts' => 3, 'notified_clients' => 2,
            'overallocated' => [['product_id' => $this->pomme->id, 'sku' => 'P0057', 'quota' => 300, 'sum_limits' => 310]],
        ],
    ]);

    // Objets JSON indexés par identifiant, dans l'ordre des lignes puis des colonnes.
    expect($response->getContent())->toContain('"cells":{"'.$this->a->id.'":{"'.$this->orange->id.'":{"limit":50,"draft":{"value":60},"pending":0,"validated":0}},'
        .'"'.$this->b->id.'":{"'.$this->pomme->id.'":{"limit":null,"draft":{"value":20},"pending":0,"validated":0},'
        .'"'.$this->orange->id.'":{"limit":100,"draft":{"value":null},"pending":0,"validated":0}},');
});

it('serves the same grid, read-only, to the ADV and the Admin', function (string $role) {
    ($this->publishQuotas)($this->centre, [[$this->orange, 500]]);
    $this->limits->saveDrafts(($this->regionDay)($this->centre), [($this->cell)($this->a, $this->orange, 40)], $this->regional);
    $this->actingAs($this->regional);
    $regional = $this->getJson($this->url)->assertOk()->json();
    $this->actingAs($role === 'admin' ? admin() : userWithRole(Role::Adv));

    $backOffice = $this->getJson("/api/v1/adv/days/2026-10-08/regions/{$this->centre->id}/grid")->assertOk()->json();

    expect($backOffice)->toBe([...$regional, 'can_edit' => false, 'can_publish' => false])
        ->and($backOffice['publication'])->toBe(['first' => true, 'drafts' => 1, 'notified_clients' => 2, 'overallocated' => []]);
})->with(['adv', 'admin']);

it('answers an empty grid while the quotas of the region are not published', function () {
    // Quotas de l'Est publiés ; le Centre n'a qu'un brouillon de l'ADV.
    $this->quotas->saveDrafts($this->day, [['region_id' => $this->centre->id, 'product_id' => $this->orange->id, 'quantity' => 500]], $this->adv);
    ($this->publishQuotas)($this->est, [[$this->orange, 200]]);
    $this->actingAs($this->regional);

    $response = $this->getJson($this->url)->assertOk()
        ->assertJsonPath('region.status', 'QUOTA_DRAFT')
        ->assertJsonPath('region.status_label', 'Quotas en brouillon')
        ->assertJsonPath('region.quota_version', 0)
        ->assertJsonPath('region.distribution_version', 0)
        ->assertJsonPath('region.distribution_published_at', null)
        ->assertJsonPath('products', [])
        ->assertJsonPath('clients', $this->clients)
        ->assertJsonPath('can_edit', false)
        ->assertJsonPath('can_publish', false)
        // Récapitulatif toujours présent : premier envoi, aux deux clients actifs.
        ->assertJsonPath('publication', ['first' => true, 'drafts' => 0, 'notified_clients' => 2, 'overallocated' => []]);

    expect($response->getContent())->toContain('"cells":{}');
});

it('shows a region created since the opening as waiting for its quotas', function () {
    $sud = Region::factory()->create(['code' => 'SUD', 'name' => 'Sud']);
    RegionAssignment::factory()->create(['user_id' => $this->regional->id, 'region_id' => $sud->id]);
    $this->actingAs($this->regional);

    $response = $this->getJson("/api/v1/regional/days/2026-10-08/grid?region={$sud->id}")->assertOk()
        ->assertJsonPath('region.code', 'SUD')
        ->assertJsonPath('region.status', 'QUOTA_DRAFT')
        ->assertJsonPath('products', [])
        ->assertJsonPath('clients', [])
        ->assertJsonPath('can_edit', false);

    expect($response->getContent())->toContain('"cells":{}')
        ->and(RegionDay::query()->where('business_day_id', $this->day->id)->where('region_id', $sud->id)->exists())->toBeTrue();
});

it('serializes a grid without any filled cell with cells as a JSON object', function () {
    ($this->publishQuotas)($this->centre, [[$this->orange, 500]]);
    $this->actingAs($this->regional);

    $response = $this->getJson($this->url)->assertOk()
        ->assertJsonPath('region.status', 'QUOTA_PUBLISHED')
        ->assertJsonPath('products.0.sum_limits', 0)
        ->assertJsonPath('can_edit', true);

    expect($response->getContent())->toContain('"cells":{}');
});

it('stays readable but no longer editable once the day or the region is closed', function () {
    ($this->publishQuotas)($this->centre, [[$this->orange, 500]]);
    $this->limits->saveDrafts(($this->regionDay)($this->centre), [($this->cell)($this->a, $this->orange, 40)], $this->regional);
    $this->calendar->close($this->day);
    $this->actingAs($this->regional);

    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('day.status', 'CLOSED')
        ->assertJsonPath('region.status', 'CLOSED')
        ->assertJsonPath('can_edit', false)
        ->assertJsonPath('can_publish', false)
        ->assertJsonPath("cells.{$this->a->id}.{$this->orange->id}.draft", ['value' => 40]);
});

it('follows the policy without limit (RG-B04) in the grid and the sending summary', function () {
    app(Settings::class)->update(['limits' => ['no_limit_policy' => 'REQUIRED']], null);
    ($this->publishQuotas)($this->centre, [[$this->orange, 500]]);
    $this->limits->saveDrafts(($this->regionDay)($this->centre), [
        ($this->cell)($this->a, $this->orange, 0),
        ($this->cell)($this->b, $this->orange, 10),
    ], $this->regional);
    $this->actingAs($this->regional);

    // Politique OBLIGATOIRE : seuls les clients avec une borne > 0 seront notifiés.
    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('policy', 'REQUIRED')
        ->assertJsonPath('publication', ['first' => true, 'drafts' => 2, 'notified_clients' => 1, 'overallocated' => []]);
});

it('reads the pending and validated quantities of the clients through ClientUsage (lot 5)', function () {
    ($this->publishQuotas)($this->centre, [[$this->orange, 500], [$this->pomme, 300]]);
    $centre = ($this->regionDay)($this->centre);
    $this->limits->saveDrafts($centre, [($this->cell)($this->a, $this->orange, 50)], $this->regional);
    $this->app->instance(ClientUsage::class, new class($this->a->id, $this->b->id, $this->orange->id, $this->pomme->id) implements ClientUsage
    {
        public function __construct(private int $a, private int $b, private int $orange, private int $pomme) {}

        public function forRegionDay(RegionDay $regionDay): array
        {
            return [
                $this->a => [$this->orange => ['pending' => 10, 'validated' => 30]],
                $this->b => [$this->orange => ['pending' => 5, 'validated' => 0], $this->pomme => ['pending' => 0, 'validated' => 0]],
            ];
        }
    });
    $this->actingAs($this->regional);

    $response = $this->getJson($this->url)->assertOk()
        ->assertJsonPath('products.0.sku', 'P0057')
        ->assertJsonPath('products.0.pending', 0)
        ->assertJsonPath('products.1.sku', 'P0042')
        ->assertJsonPath('products.1.pending', 15);

    // Une cellule sans borne mais avec des commandes ; rien pour une consommation nulle.
    expect($response->json('cells'))->toBe([
        (string) $this->a->id => [(string) $this->orange->id => ['limit' => null, 'draft' => ['value' => 50], 'pending' => 10, 'validated' => 30]],
        (string) $this->b->id => [(string) $this->orange->id => ['limit' => null, 'draft' => null, 'pending' => 5, 'validated' => 0]],
    ]);
});

it('loads the grid with a bounded number of queries', function () {
    ($this->publishQuotas)($this->centre, [[$this->orange, 500]]);
    $this->limits->saveDrafts(($this->regionDay)($this->centre), [($this->cell)($this->a, $this->orange, 10)], $this->regional);
    $this->actingAs($this->regional);
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($this->url)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $queries(); // Réchauffe les caches (permissions, paramètres).
    $few = $queries();

    // 30 clients × 10 produits, toutes les cellules renseignées, publiées puis modifiées.
    $retail = ClientCategory::factory()->create(['code' => 'DET', 'name' => 'Détaillant']);
    $clients = Client::factory()->count(27)
        ->sequence(['category_id' => $this->wholesale->id], ['category_id' => $retail->id], ['category_id' => null])
        ->create(['region_id' => $this->centre->id]);
    $products = Product::factory()->count(8)->create(['family_id' => ProductFamily::factory()]);
    $all = [$this->orange, $this->pomme, ...$products];
    ($this->publishQuotas)($this->centre, array_map(fn (Product $product) => [$product, 1000], $all));
    $centre = ($this->regionDay)($this->centre);
    $cells = [];
    foreach ([$this->a, $this->b, $this->blocked, ...$clients] as $client) {
        foreach ($all as $product) {
            $cells[] = ($this->cell)($client, $product, 20);
        }
    }
    $this->limits->saveDrafts($centre, $cells, $this->regional);
    $this->limits->publish($centre, $this->regional);
    $this->limits->saveDrafts($centre, array_map(fn (array $cell) => [...$cell, 'limit' => 25], $cells), $this->regional);

    $many = $queries();
    $response = $this->getJson($this->url);
    expect($many)->toBe($few)
        ->and($response->json('clients'))->toHaveCount(30)
        ->and($response->json('products'))->toHaveCount(10)
        ->and(collect($response->json('cells'))->flatten(1))->toHaveCount(300)
        ->and($response->json('products.0.sum_limits'))->toBe(750);
});
