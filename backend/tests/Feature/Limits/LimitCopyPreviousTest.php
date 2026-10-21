<?php

use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Domain\Days\RegionDayStatus;
use App\Domain\Limits\LimitBook;
use App\Domain\Quotas\QuotaBook;
use App\Events\DayClosed;
use App\Events\DistributionPublished;
use App\Events\LimitDraftsSaved;
use App\Events\QuotaDraftsSaved;
use App\Events\QuotasPublished;
use App\Models\BusinessDay;
use App\Models\Client;
use App\Models\ClientLimit;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionDay;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

// « Reprendre J-1 » : POST /regional/days/{day}/limits/copy-previous
// (RG-B08 ; contrat du lot 4, §3).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 08:30', 'Africa/Algiers'));
    Event::fake([QuotasPublished::class, QuotaDraftsSaved::class, LimitDraftsSaved::class, DistributionPublished::class, DayClosed::class]);
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->regional = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['user_id' => $this->regional->id, 'region_id' => $this->centre->id]);
    $this->a = Client::factory()->create(['code' => 'C0012', 'region_id' => $this->centre->id]);
    $this->b = Client::factory()->create(['code' => 'C0047', 'region_id' => $this->centre->id]);
    $this->orange = Product::factory()->create(['sku' => 'P0042']);
    $this->pomme = Product::factory()->create(['sku' => 'P0057']);
    $this->lben = Product::factory()->create(['sku' => 'P0103']);

    $this->calendar = app(DayCalendar::class);
    $this->quotas = app(QuotaBook::class);
    $this->book = app(LimitBook::class);
    $this->cell = fn (Client $client, Product $product, ?int $limit) => ['client_id' => $client->id, 'product_id' => $product->id, 'limit' => $limit];
    // Journée ouverte avec les quotas du Centre publiés ; renvoie sa journée régionale.
    $this->open = function (string $date, array $quantities): RegionDay {
        $day = $this->calendar->open(BusinessClock::parseDay($date), $this->adv);
        $cells = [];
        foreach ($quantities as [$product, $quantity]) {
            $cells[] = ['region_id' => $this->centre->id, 'product_id' => $product->id, 'quantity' => $quantity];
        }
        $this->quotas->saveDrafts($day, $cells, $this->adv);
        $this->quotas->publish($day, [$this->centre->id], $this->adv);

        return RegionDay::query()->where('business_day_id', $day->id)->where('region_id', $this->centre->id)->sole();
    };
    $this->limit = fn (RegionDay $regionDay, Client $client, Product $product) => ClientLimit::query()
        ->where('region_day_id', $regionDay->id)->where('client_id', $client->id)->where('product_id', $product->id)->first();
    $this->copy = fn (string $mode, string $day = '2026-10-08') => $this->postJson("/api/v1/regional/days/{$day}/limits/copy-previous", [
        'region_id' => $this->centre->id,
        'mode' => $mode,
    ]);
    $this->actingAs($this->regional);
});

it('takes back the limits of the last distribution sent, capped to the quota of the day: fill the empty cells, then replace (RG-B08)', function () {
    // Le 06/10, répartition envoyée ; le 07/10, jamais envoyée : la source est le 06/10.
    $source = ($this->open)('2026-10-06', [[$this->orange, 500], [$this->pomme, 300]]);
    $this->book->saveDrafts($source, [
        ($this->cell)($this->a, $this->orange, 400),
        ($this->cell)($this->b, $this->orange, 100),
        ($this->cell)($this->a, $this->pomme, 50),
    ], $this->regional);
    $this->book->publish($source, $this->regional);
    $unsent = ($this->open)('2026-10-07', [[$this->orange, 500]]);
    $this->book->saveDrafts($unsent, [($this->cell)($this->a, $this->orange, 1)], $this->regional);

    // Aujourd'hui : orange réduit à 300, pomme sans quota ; B a déjà une borne saisie.
    $today = ($this->open)('2026-10-08', [[$this->orange, 300], [$this->lben, 200]]);
    $this->book->saveDrafts($today, [($this->cell)($this->b, $this->orange, 20)], $this->regional);

    ($this->copy)('FILL')->assertOk()->assertExactJson(['changed' => 1, 'source_day' => '2026-10-06']);
    expect(($this->limit)($today, $this->a, $this->orange))->draft_max_qty->toBe(300)->has_draft->toBeTrue()
        ->and(($this->limit)($today, $this->b, $this->orange)->draft_max_qty)->toBe(20)
        ->and(($this->limit)($today, $this->a, $this->pomme))->toBeNull();

    // Écraser : B reprend 100 ; le lben, sans borne la veille, reste sans borne.
    ($this->copy)('REPLACE')->assertOk()->assertExactJson(['changed' => 1, 'source_day' => '2026-10-06']);
    expect(($this->limit)($today, $this->b, $this->orange)->draft_max_qty)->toBe(100)
        ->and(($this->limit)($today, $this->a, $this->lben))->toBeNull()
        // Le résultat est un brouillon, rien n'est envoyé aux clients.
        ->and($today->fresh())->status->toBe(RegionDayStatus::DistributionDraft)->distribution_version->toBe(0);

    // Une répartition envoyée le 07/10 devient la source.
    $this->book->publish($unsent, $this->regional);
    ($this->copy)('REPLACE')->assertOk()->assertExactJson(['changed' => 2, 'source_day' => '2026-10-07']);
    expect(($this->limit)($today, $this->a, $this->orange)->draft_max_qty)->toBe(1)
        ->and(($this->limit)($today, $this->b, $this->orange)->draft_max_qty)->toBeNull();
});

it('answers 422 on source when no distribution was ever sent for the region', function () {
    ($this->open)('2026-10-08', [[$this->orange, 300]]);

    ($this->copy)('FILL')
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonValidationErrors(['source' => 'Aucune répartition n\'a encore été envoyée pour cette région.']);
});

it('validates the mode', function (array $body, array $errors) {
    ($this->open)('2026-10-08', [[$this->orange, 300]]);

    $this->postJson('/api/v1/regional/days/2026-10-08/limits/copy-previous', ['region_id' => $this->centre->id, ...$body])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);
})->with([
    'missing' => [[], ['mode' => 'Le champ mode est obligatoire.']],
    'unknown' => [['mode' => 'OVERWRITE'], ['mode' => 'Mode attendu : REPLACE (écraser) ou FILL (compléter les cases vides).']],
    'lower case' => [['mode' => 'fill'], ['mode']],
    'not a string' => [['mode' => ['FILL']], ['mode']],
]);

it('waits for the quotas of the region and stops at the closing', function () {
    $this->calendar->open(BusinessClock::parseDay('2026-10-08'), $this->adv);

    expectProblem(($this->copy)('FILL'), 409, 'DAY_NOT_OPEN');

    $this->calendar->close(BusinessDay::forDay('2026-10-08'));
    expectProblem(($this->copy)('FILL'), 409, 'DAY_NOT_OPEN');
});
