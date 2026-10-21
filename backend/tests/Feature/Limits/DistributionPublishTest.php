<?php

use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Domain\Days\RegionDayStatus;
use App\Domain\Limits\ClientUsage;
use App\Domain\Quotas\QuotaBook;
use App\Domain\Settings\Settings;
use App\Events\DayClosed;
use App\Events\DistributionPublished;
use App\Events\LimitDraftsSaved;
use App\Events\QuotaDraftsSaved;
use App\Events\QuotasPublished;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientLimit;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionDay;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

// « Valider et envoyer aux clients » : POST /regional/days/{day}/distribution/publish
// (RG-B05 à RG-B07 ; contrat du lot 4, §3).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 08:30', 'Africa/Algiers'));
    Event::fake([QuotasPublished::class, QuotaDraftsSaved::class, LimitDraftsSaved::class, DistributionPublished::class, DayClosed::class]);
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->regional = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['user_id' => $this->regional->id, 'region_id' => $this->centre->id]);
    $this->a = Client::factory()->create(['code' => 'C0012', 'region_id' => $this->centre->id]);
    $this->b = Client::factory()->create(['code' => 'C0047', 'region_id' => $this->centre->id]);
    Client::factory()->blocked()->create(['code' => 'C0050', 'region_id' => $this->centre->id]);
    $this->orange = Product::factory()->create(['sku' => 'P0042']);
    $this->pomme = Product::factory()->create(['sku' => 'P0057']);

    $this->calendar = app(DayCalendar::class);
    $this->quotas = app(QuotaBook::class);
    $this->day = $this->calendar->open(BusinessClock::parseDay('2026-10-08'), $this->adv);
    $this->publishQuotas = function (array $quantities) {
        $cells = [];
        foreach ($quantities as [$product, $quantity]) {
            $cells[] = ['region_id' => $this->centre->id, 'product_id' => $product->id, 'quantity' => $quantity];
        }
        $this->quotas->saveDrafts($this->day, $cells, $this->adv);
        $this->quotas->publish($this->day, [$this->centre->id], $this->adv);
    };
    ($this->publishQuotas)([[$this->orange, 500], [$this->pomme, 300]]);
    $this->regionDay = RegionDay::query()->where('business_day_id', $this->day->id)->where('region_id', $this->centre->id)->sole();

    $this->cell = fn (Client $client, Product $product, ?int $limit) => ['client_id' => $client->id, 'product_id' => $product->id, 'limit' => $limit];
    $this->save = fn (array $cells) => $this->putJson('/api/v1/regional/days/2026-10-08/limits', ['region_id' => $this->centre->id, 'cells' => $cells])->assertOk();
    $this->publish = fn () => $this->postJson('/api/v1/regional/days/2026-10-08/distribution/publish', ['region_id' => $this->centre->id]);
    $this->limit = fn (Client $client, Product $product) => ClientLimit::query()
        ->where('region_day_id', $this->regionDay->id)->where('client_id', $client->id)->where('product_id', $product->id)->first();
    $this->actingAs($this->regional);
});

it('sends a first distribution, even without any limit, to the active clients of the region (RG-B04, RG-B06)', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 08:40', 'Africa/Algiers'));

    ($this->publish)()->assertOk()->assertExactJson([
        'version' => 1,
        'first' => true,
        'changes' => 0,
        // Le client bloqué n'est pas notifié.
        'notified_clients' => 2,
        'overallocated' => [],
    ]);

    $regionDay = $this->regionDay->fresh();
    expect($regionDay->status)->toBe(RegionDayStatus::DistributionPublished)
        ->and($regionDay->distribution_version)->toBe(1)
        ->and($regionDay->distribution_published_at->toIso8601String())->toBe('2026-10-08T07:40:00+00:00')
        ->and($regionDay->distribution_published_by)->toBe($this->regional->id);
    Event::assertDispatched(DistributionPublished::class, fn (DistributionPublished $e) => $e->regionDayId === $this->regionDay->id
        && $e->version === 1 && $e->first && $e->notifiedClientIds === [$this->a->id, $this->b->id] && $e->actorId === $this->regional->id);

    // Le récapitulatif de la grille reflète l'envoi.
    $this->getJson("/api/v1/regional/days/2026-10-08/grid?region={$this->centre->id}")->assertOk()
        ->assertJsonPath('region.distribution_version', 1)
        ->assertJsonPath('region.distribution_published_at', '2026-10-08T07:40:00+00:00')
        ->assertJsonPath('publication', ['first' => false, 'drafts' => 0, 'notified_clients' => 0, 'overallocated' => []]);
});

it('publishes the drafts, signals the over-allocation and journals each sending (RG-B05, RG-B07)', function () {
    ($this->save)([($this->cell)($this->a, $this->orange, 300), ($this->cell)($this->b, $this->orange, 250)]);

    // Surallocation permise et signalée : 550 > 500.
    ($this->publish)()->assertOk()->assertExactJson([
        'version' => 1, 'first' => true, 'changes' => 2, 'notified_clients' => 2,
        'overallocated' => [['product_id' => $this->orange->id, 'sku' => 'P0042', 'quota' => 500, 'sum_limits' => 550]],
    ]);
    expect(($this->limit)($this->a, $this->orange))->max_qty->toBe(300)->has_draft->toBeFalse();

    // Envoi suivant sans brouillon : refusé.
    $response = ($this->publish)();
    expectProblem($response, 409, 'INVALID_TRANSITION');
    $response->assertJsonPath('detail', 'Aucune modification à envoyer.');

    // Modifications : seuls les clients impactés sont notifiés (N-04).
    ($this->save)([($this->cell)($this->b, $this->orange, 200), ($this->cell)($this->b, $this->pomme, null)]);
    ($this->publish)()->assertOk()->assertExactJson([
        'version' => 2, 'first' => false, 'changes' => 1, 'notified_clients' => 1, 'overallocated' => [],
    ]);
    expect($this->regionDay->fresh())->distribution_version->toBe(2)->status->toBe(RegionDayStatus::DistributionPublished);

    $entries = AuditLog::query()->where('log_name', 'limits')->orderBy('id')->get();
    expect($entries->pluck('event')->all())->toBe(['published', 'updated'])
        ->and($entries->pluck('causer_id')->all())->toBe([$this->regional->id, $this->regional->id])
        ->and($entries[0]->description)->toBe('Répartition envoyée aux clients')
        // toEqual : MySQL réordonne les clés des colonnes JSON.
        ->and($entries[1]->properties->all())->toEqual([
            'day' => '2026-10-08',
            'region' => 'CENTRE',
            'version' => 2,
            'changes_count' => 1,
            'changes' => [['client_id' => $this->b->id, 'client' => 'C0047', 'product_id' => $this->orange->id, 'sku' => 'P0042', 'old' => 250, 'new' => 200]],
            'notified_clients' => 1,
        ]);
});

it('refuses the whole sending when a limit exceeds a quota decreased since its entry (RG-B03, RG-Q06)', function () {
    ($this->save)([($this->cell)($this->a, $this->orange, 400), ($this->cell)($this->b, $this->pomme, 50)]);
    ($this->publishQuotas)([[$this->orange, 350]]);

    $response = ($this->publish)();

    expectProblem($response, 422, 'VALIDATION_FAILED');
    $response->assertJsonPath('detail', 'Des bornes dépassent le quota de la région, diminué depuis leur saisie : corrigez-les avant l\'envoi.')
        ->assertJsonPath('violations', [
            ['client_id' => $this->a->id, 'client' => 'C0012', 'product_id' => $this->orange->id, 'sku' => 'P0042', 'requested' => 400, 'quota' => 350],
        ]);
    expect($this->regionDay->fresh()->distribution_version)->toBe(0)
        ->and(($this->limit)($this->b, $this->pomme))->max_qty->toBeNull()->has_draft->toBeTrue();
    Event::assertNotDispatched(DistributionPublished::class);
});

it('refuses the whole sending when a limit is under the quantity already validated (RG-B07)', function () {
    $this->app->instance(ClientUsage::class, new class($this->a->id, $this->orange->id) implements ClientUsage
    {
        public function __construct(private int $client, private int $product) {}

        public function forRegionDay(RegionDay $regionDay): array
        {
            return [$this->client => [$this->product => ['pending' => 0, 'validated' => 30]]];
        }
    });
    ($this->save)([($this->cell)($this->a, $this->orange, 20), ($this->cell)($this->b, $this->orange, 40)]);

    $response = ($this->publish)();

    expectProblem($response, 409, 'QUOTA_BELOW_VALIDATED');
    $response->assertJsonPath('violations', [
        ['client_id' => $this->a->id, 'client' => 'C0012', 'product_id' => $this->orange->id, 'sku' => 'P0042', 'requested' => 20, 'validated' => 30],
    ]);
    expect($this->regionDay->fresh()->distribution_version)->toBe(0);
});

it('erases the drafts that became pointless instead of sending them', function () {
    ($this->save)([($this->cell)($this->a, $this->orange, 50), ($this->cell)($this->a, $this->pomme, 10)]);
    // La pomme n'a plus de quota dans la région.
    ($this->publishQuotas)([[$this->pomme, 0]]);

    ($this->publish)()->assertOk()->assertJsonPath('changes', 1);
    expect(($this->limit)($this->a, $this->pomme))->has_draft->toBeFalse()->max_qty->toBeNull();
});

it('notifies only the clients with a limit above 0 in REQUIRED policy (RG-B04, RG-B06)', function () {
    app(Settings::class)->update(['limits' => ['no_limit_policy' => 'REQUIRED']], null);
    ($this->save)([($this->cell)($this->a, $this->orange, 0), ($this->cell)($this->b, $this->orange, 10)]);

    ($this->publish)()->assertOk()->assertJsonPath('notified_clients', 1);
    Event::assertDispatched(DistributionPublished::class, fn (DistributionPublished $e) => $e->notifiedClientIds === [$this->b->id]);
});

it('waits for the quotas of the region, and stays possible after the distribution cutoff until the closing (§4.3)', function () {
    $sud = Region::factory()->create(['code' => 'SUD']);
    RegionAssignment::factory()->create(['user_id' => $this->regional->id, 'region_id' => $sud->id]);
    $response = $this->postJson('/api/v1/regional/days/2026-10-08/distribution/publish', ['region_id' => $sud->id]);
    expectProblem($response, 409, 'DAY_NOT_OPEN');
    $response->assertJsonPath('detail', 'Les quotas de la région SUD du 08/10/2026 ne sont pas encore publiés.');

    // 12:00 : l'heure limite de répartition (10:00) est passée, l'envoi reste possible.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00', 'Africa/Algiers'));
    ($this->publish)()->assertOk()->assertJsonPath('version', 1);

    // Après la clôture, plus rien ne part.
    ($this->save)([($this->cell)($this->a, $this->orange, 10)]);
    $this->calendar->close($this->day);
    expectProblem(($this->publish)(), 409, 'DAY_NOT_OPEN');
    expect(($this->limit)($this->a, $this->orange))->has_draft->toBeTrue()
        ->and($this->regionDay->fresh()->distribution_version)->toBe(1);
});
