<?php

use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Domain\Days\RegionDayStatus;
use App\Domain\Limits\ClientUsage;
use App\Domain\Limits\LimitBook;
use App\Domain\Quotas\QuotaBook;
use App\Events\DayClosed;
use App\Events\DistributionPublished;
use App\Events\LimitDraftsSaved;
use App\Events\QuotaDraftsSaved;
use App\Events\QuotasPublished;
use App\Models\Client;
use App\Models\ClientLimit;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionDay;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

// Saisie des bornes en brouillon : PUT /regional/days/{day}/limits
// (RG-B01 à RG-B03, RG-B07, RG-B09 ; contrat du lot 4, §3).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 08:30', 'Africa/Algiers'));
    Event::fake([QuotasPublished::class, QuotaDraftsSaved::class, LimitDraftsSaved::class, DistributionPublished::class, DayClosed::class]);
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->est = Region::factory()->create(['code' => 'EST']);
    $this->regional = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['user_id' => $this->regional->id, 'region_id' => $this->centre->id]);
    $this->a = Client::factory()->create(['code' => 'C0012', 'region_id' => $this->centre->id]);
    $this->b = Client::factory()->create(['code' => 'C0047', 'region_id' => $this->centre->id]);
    $this->elsewhere = Client::factory()->create(['code' => 'C0099', 'region_id' => $this->est->id]);
    $this->orange = Product::factory()->create(['sku' => 'P0042']);
    $this->pomme = Product::factory()->create(['sku' => 'P0057']);
    $this->lben = Product::factory()->create(['sku' => 'P0103']);

    $this->calendar = app(DayCalendar::class);
    $this->quotas = app(QuotaBook::class);
    $this->day = $this->calendar->open(BusinessClock::parseDay('2026-10-08'), $this->adv);
    $this->quotas->saveDrafts($this->day, [
        ['region_id' => $this->centre->id, 'product_id' => $this->orange->id, 'quantity' => 500],
        ['region_id' => $this->centre->id, 'product_id' => $this->pomme->id, 'quantity' => 300],
        ['region_id' => $this->est->id, 'product_id' => $this->lben->id, 'quantity' => 100],
    ], $this->adv);
    $this->quotas->publish($this->day, [$this->centre->id, $this->est->id], $this->adv);
    $this->regionDay = RegionDay::query()->where('business_day_id', $this->day->id)->where('region_id', $this->centre->id)->sole();

    $this->url = '/api/v1/regional/days/2026-10-08/limits';
    $this->cell = fn (Client $client, Product $product, mixed $limit) => ['client_id' => $client->id, 'product_id' => $product->id, 'limit' => $limit];
    $this->save = fn (array $cells) => $this->putJson($this->url, ['region_id' => $this->centre->id, 'cells' => $cells]);
    $this->limit = fn (Client $client, Product $product) => ClientLimit::query()
        ->where('region_day_id', $this->regionDay->id)->where('client_id', $client->id)->where('product_id', $product->id)->first();
    $this->actingAs($this->regional);
});

it('saves limits as drafts, moves the region to « répartition en cours » and returns the state of the cells sent (RG-B09, §6.1)', function () {
    expect($this->regionDay->status)->toBe(RegionDayStatus::QuotaPublished);

    ($this->save)([
        ($this->cell)($this->a, $this->orange, 50),
        // 0 : produit interdit à ce client (RG-B01).
        ($this->cell)($this->b, $this->orange, 0),
        // « Pas de borne » sans borne publiée : rien à enregistrer.
        ($this->cell)($this->b, $this->pomme, null),
    ])->assertOk()->assertExactJson([
        'changed' => 2,
        'status' => 'DISTRIBUTION_DRAFT',
        'cells' => [
            ['client_id' => $this->a->id, 'product_id' => $this->orange->id, 'limit' => null, 'draft' => ['value' => 50], 'pending' => 0, 'validated' => 0],
            ['client_id' => $this->b->id, 'product_id' => $this->orange->id, 'limit' => null, 'draft' => ['value' => 0], 'pending' => 0, 'validated' => 0],
            ['client_id' => $this->b->id, 'product_id' => $this->pomme->id, 'limit' => null, 'draft' => null, 'pending' => 0, 'validated' => 0],
        ],
    ]);

    expect($this->regionDay->fresh()->status)->toBe(RegionDayStatus::DistributionDraft)
        ->and(($this->limit)($this->a, $this->orange))->has_draft->toBeTrue()->draft_max_qty->toBe(50)->updated_by->toBe($this->regional->id)
        ->and(($this->limit)($this->b, $this->pomme))->toBeNull();
    Event::assertDispatched(LimitDraftsSaved::class, fn (LimitDraftsSaved $e) => $e->regionDayId === $this->regionDay->id && $e->actorId === $this->regional->id);

    // Même saisie : rien ne change, aucun événement de plus.
    ($this->save)([($this->cell)($this->a, $this->orange, 50)])->assertOk()->assertJsonPath('changed', 0);
    Event::assertDispatchedTimes(LimitDraftsSaved::class, 1);
});

it('keeps the published limit beside the draft, and cancels a draft equal to the published limit (RG-B07)', function () {
    ($this->save)([($this->cell)($this->a, $this->orange, 50), ($this->cell)($this->b, $this->orange, 80)])->assertOk();
    app(LimitBook::class)->publish($this->regionDay, $this->regional);

    ($this->save)([($this->cell)($this->a, $this->orange, 60), ($this->cell)($this->b, $this->orange, null)])
        ->assertOk()
        ->assertJsonPath('changed', 2)
        // Après l'envoi, le statut reste « Répartition publiée ».
        ->assertJsonPath('status', 'DISTRIBUTION_PUBLISHED')
        ->assertJsonPath('cells', [
            ['client_id' => $this->a->id, 'product_id' => $this->orange->id, 'limit' => 50, 'draft' => ['value' => 60], 'pending' => 0, 'validated' => 0],
            // Suppression de la borne en attente d'envoi.
            ['client_id' => $this->b->id, 'product_id' => $this->orange->id, 'limit' => 80, 'draft' => ['value' => null], 'pending' => 0, 'validated' => 0],
        ]);

    // Annuler un brouillon : renvoyer la borne publiée.
    ($this->save)([($this->cell)($this->a, $this->orange, 50)])
        ->assertOk()
        ->assertJsonPath('changed', 1)
        ->assertJsonPath('cells.0', ['client_id' => $this->a->id, 'product_id' => $this->orange->id, 'limit' => 50, 'draft' => null, 'pending' => 0, 'validated' => 0]);
});

it('validates the format of the cells', function (array $body, array $errors) {
    $this->putJson($this->url, ['region_id' => $this->centre->id, ...$body])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonValidationErrors($errors);
    expect(ClientLimit::query()->count())->toBe(0);
})->with([
    'no cells' => [[], ['cells' => 'Le champ cellules est obligatoire.']],
    'empty list' => [['cells' => []], ['cells']],
    'not a list' => [['cells' => ['a' => ['client_id' => 1, 'product_id' => 1, 'limit' => 1]]], ['cells' => 'Le champ cellules doit être une liste.']],
    'not a cell' => [['cells' => ['x']], ['cells.0']],
    'missing client' => [['cells' => [['product_id' => 1, 'limit' => 1]]], ['cells.0.client_id' => 'Le champ client est obligatoire.']],
    'textual product' => [['cells' => [['client_id' => 1, 'product_id' => '1', 'limit' => 1]]], ['cells.0.product_id' => 'Le champ produit doit être un nombre entier.']],
    'missing limit' => [['cells' => [['client_id' => 1, 'product_id' => 1]]], ['cells.0.limit' => 'Le champ borne doit être présent.']],
]);

it('accepts integers from 0 to 1 000 000, or null, as limits (RG-B01)', function (mixed $limit) {
    ($this->save)([($this->cell)($this->a, $this->orange, $limit)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cells.0.limit' => 'La borne doit être un nombre entier de 0 à 1 000 000, ou vide pour « pas de borne ».']);
})->with([
    'negative' => [-1],
    'too large' => [1_000_001],
    'decimal' => [12.5],
    'text' => ['12'],
    'boolean' => [true],
]);

it('refuses more than 2 000 cells in one request', function () {
    $cells = [];
    for ($i = 0; $i <= 2000; $i++) {
        $cells[] = ['client_id' => $i + 1, 'product_id' => $this->orange->id, 'limit' => 1];
    }

    ($this->save)($cells)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cells' => 'Le champ cellules ne doit pas contenir plus de 2000 éléments.']);
});

it('refuses the same client and product twice in one request', function () {
    ($this->save)([
        ($this->cell)($this->a, $this->orange, 10),
        ($this->cell)($this->a, $this->pomme, 10),
        ($this->cell)($this->a, $this->orange, 20),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['cells.2.product_id' => 'Ce client et ce produit figurent déjà dans la requête (élément 0).']);
    expect(ClientLimit::query()->count())->toBe(0);
});

it('refuses a client outside the region, a product without quota and a limit above the quota, all or nothing (RG-B02, RG-B03)', function () {
    ($this->save)([
        ($this->cell)($this->b, $this->pomme, 10),
        ($this->cell)($this->elsewhere, $this->orange, 10),
        // Le lben n'a de quota qu'à l'Est.
        ($this->cell)($this->a, $this->lben, 10),
        ($this->cell)($this->a, $this->orange, 501),
    ])->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonPath('errors', [
            'cells.1.client_id' => ['Client hors de la région ce jour-là.'],
            'cells.2.product_id' => ['Produit sans quota publié dans la région ce jour-là.'],
            'cells.3.limit' => ['La borne ne peut pas dépasser le quota de la région (500).'],
        ]);

    expect(ClientLimit::query()->count())->toBe(0)
        ->and($this->regionDay->fresh()->status)->toBe(RegionDayStatus::QuotaPublished);
    // Le quota lui-même est accepté (RG-B03).
    ($this->save)([($this->cell)($this->a, $this->orange, 500)])->assertOk()->assertJsonPath('changed', 1);
});

it('refuses a limit under the quantity already validated once the distribution is sent (RG-B07)', function () {
    $this->app->instance(ClientUsage::class, new class($this->a->id, $this->orange->id) implements ClientUsage
    {
        public function __construct(private int $client, private int $product) {}

        public function forRegionDay(RegionDay $regionDay): array
        {
            return [$this->client => [$this->product => ['pending' => 10, 'validated' => 30]]];
        }
    });
    ($this->save)([($this->cell)($this->a, $this->orange, 50)])->assertOk()
        ->assertJsonPath('cells.0', ['client_id' => $this->a->id, 'product_id' => $this->orange->id, 'limit' => null, 'draft' => ['value' => 50], 'pending' => 10, 'validated' => 30]);
    app(LimitBook::class)->publish($this->regionDay, $this->regional);

    ($this->save)([($this->cell)($this->a, $this->orange, 29)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cells.0.limit' => 'Borne inférieure aux 30 déjà validés pour ce client.']);
    // Sous E(c,p) = S + V mais pas sous V : permis, l'écran avertit.
    ($this->save)([($this->cell)($this->a, $this->orange, 35)])->assertOk()->assertJsonPath('changed', 1);
});

it('waits for the quotas of the region (RG-B06)', function () {
    $sud = Region::factory()->create(['code' => 'SUD']);
    RegionAssignment::factory()->create(['user_id' => $this->regional->id, 'region_id' => $sud->id]);
    $sudClient = Client::factory()->create(['region_id' => $sud->id]);

    $response = $this->putJson($this->url, ['region_id' => $sud->id, 'cells' => [($this->cell)($sudClient, $this->orange, 5)]]);

    expectProblem($response, 409, 'DAY_NOT_OPEN');
    $response->assertJsonPath('detail', 'Les quotas de la région SUD du 08/10/2026 ne sont pas encore publiés.');
});

it('refuses any change once the day is closed', function () {
    $sud = Region::factory()->create(['code' => 'SUD']);
    RegionAssignment::factory()->create(['user_id' => $this->regional->id, 'region_id' => $sud->id]);
    $this->calendar->close($this->day);

    $response = ($this->save)([($this->cell)($this->a, $this->orange, 5)]);
    expectProblem($response, 409, 'DAY_NOT_OPEN');
    $response->assertJsonPath('detail', 'La journée du 08/10/2026 est clôturée : elle n\'est plus modifiable.');

    // Région créée depuis : pas de journée régionale créée sur une journée clôturée.
    expectProblem($this->putJson($this->url, ['region_id' => $sud->id, 'cells' => [($this->cell)($this->a, $this->orange, 5)]]), 409, 'DAY_NOT_OPEN');
    expect(RegionDay::query()->where('region_id', $sud->id)->exists())->toBeFalse()
        ->and(ClientLimit::query()->count())->toBe(0);
});
