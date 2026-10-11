<?php

use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Domain\Quotas\QuotaBook;
use App\Events\QuotaDraftsSaved;
use App\Events\QuotasPublished;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionQuota;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

// « Copier depuis la journée… » : POST /adv/days/{day}/quotas/copy (EF-ADV-02, contrat du lot 3 §4).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 07:30', 'Africa/Algiers'));
    Event::fake([QuotasPublished::class, QuotaDraftsSaved::class]);
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->est = Region::factory()->create(['code' => 'EST']);
    $this->orange = Product::factory()->create(['sku' => 'P0042']);
    $this->pomme = Product::factory()->create(['sku' => 'P0057']);
    $this->book = app(QuotaBook::class);
    $this->calendar = app(DayCalendar::class);
    $this->cell = fn (Region $region, Product $product, ?int $quantity) => ['region_id' => $region->id, 'product_id' => $product->id, 'quantity' => $quantity];

    // Veille publiée puis clôturée ; un brouillon non publié n'est jamais copié.
    $this->previous = $this->calendar->open(BusinessClock::parseDay('2026-10-07'), $this->adv);
    $this->book->saveDrafts($this->previous, [
        ($this->cell)($this->centre, $this->orange, 400),
        ($this->cell)($this->centre, $this->pomme, 100),
        ($this->cell)($this->est, $this->orange, 250),
    ], $this->adv);
    $this->book->publish($this->previous, null, $this->adv);
    $this->book->saveDrafts($this->previous, [($this->cell)($this->est, $this->pomme, 999)], $this->adv);
    $this->travelTo(CarbonImmutable::parse('2026-10-08 07:30', 'Africa/Algiers'));
    $this->calendar->closeDue();

    $this->day = $this->calendar->open(BusinessClock::parseDay('2026-10-08'), $this->adv);
    $this->url = '/api/v1/adv/days/2026-10-08/quotas/copy';
    $this->value = fn (Region $region, Product $product) => RegionQuota::query()
        ->where('product_id', $product->id)
        ->whereHas('regionDay', fn ($q) => $q->where('business_day_id', $this->day->id)->where('region_id', $region->id))
        ->value('draft_quantity');
});

it('requires quota.edit and the back-office before validating the body', function (Role $role) {
    $this->actingAs(userWithRole($role));

    expectProblem($this->postJson($this->url, []), 403, 'FORBIDDEN');
    expectProblem($this->postJson($this->url, ['source_day' => '2026-10-07', 'mode' => 'REPLACE']), 403, 'FORBIDDEN');
})->with([Role::Admin, Role::Regional, Role::Client]);

it('fills only the empty cells of the chosen regions (FILL)', function () {
    $this->book->saveDrafts($this->day, [($this->cell)($this->centre, $this->orange, 999)], $this->adv);
    $this->actingAs($this->adv);

    $this->postJson($this->url, ['source_day' => '2026-10-07', 'mode' => 'FILL', 'region_ids' => [$this->centre->id]])
        ->assertOk()
        ->assertExactJson(['changed' => 1]);

    expect(($this->value)($this->centre, $this->orange))->toBe(999)
        ->and(($this->value)($this->centre, $this->pomme))->toBe(100)
        ->and(($this->value)($this->est, $this->orange))->toBeNull();
});

it('replaces every cell of the perimeter with the published values of the source (REPLACE)', function () {
    $this->book->saveDrafts($this->day, [
        ($this->cell)($this->centre, $this->orange, 999),
        ($this->cell)($this->est, $this->pomme, 50),
    ], $this->adv);
    $this->actingAs($this->adv);

    $this->postJson($this->url, ['source_day' => '2026-10-07', 'mode' => 'REPLACE'])
        ->assertOk()
        ->assertExactJson(['changed' => 4]);

    expect(($this->value)($this->centre, $this->orange))->toBe(400)
        ->and(($this->value)($this->centre, $this->pomme))->toBe(100)
        ->and(($this->value)($this->est, $this->orange))->toBe(250)
        // Brouillon de la source jamais publié : la cellule repart de 0.
        ->and(($this->value)($this->est, $this->pomme))->toBeNull();
    Event::assertDispatched(QuotaDraftsSaved::class, fn (QuotaDraftsSaved $e) => $e->businessDayId === $this->day->id);
});

it('copies only the regions of a restricted ADV, and refuses the others (RG-S05)', function () {
    $restricted = userWithRole(Role::Adv);
    RegionAssignment::factory()->adv()->create(['user_id' => $restricted->id, 'region_id' => $this->est->id]);
    $this->actingAs($restricted);

    $this->postJson($this->url, ['source_day' => '2026-10-07', 'mode' => 'REPLACE', 'region_ids' => [$this->centre->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['region_ids' => 'Région hors de votre périmètre.']);

    $this->postJson($this->url, ['source_day' => '2026-10-07', 'mode' => 'REPLACE'])->assertOk()->assertJsonPath('changed', 1);
    expect(($this->value)($this->est, $this->orange))->toBe(250)
        ->and(($this->value)($this->centre, $this->orange))->toBeNull();
});

it('validates the copy', function (array $payload, string $field, ?string $message = null) {
    $this->actingAs($this->adv);

    $response = $this->postJson($this->url, [
        'source_day' => '2026-10-07',
        'mode' => 'REPLACE',
        ...$payload,
    ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');

    $message === null ? $response->assertJsonValidationErrors($field) : $response->assertJsonValidationErrors([$field => $message]);
    expect(RegionQuota::query()->whereHas('regionDay', fn ($q) => $q->where('business_day_id', $this->day->id))->count())->toBe(0);
})->with([
    'missing source day' => [['source_day' => null], 'source_day'],
    'French date' => [['source_day' => '07/10/2026'], 'source_day', 'Date attendue au format AAAA-MM-JJ.'],
    'impossible date' => [['source_day' => '2026-02-30'], 'source_day'],
    'day never opened' => [['source_day' => '2026-10-01'], 'source_day', 'Aucune journée n\'a été ouverte le 01/10/2026.'],
    'same day' => [['source_day' => '2026-10-08'], 'source_day', 'La journée source doit être différente de la journée à remplir.'],
    'missing mode' => [['mode' => null], 'mode'],
    'unknown mode' => [['mode' => 'COMPLETE'], 'mode'],
    'empty region list' => [['region_ids' => []], 'region_ids'],
    'region list as text' => [['region_ids' => 'CENTRE'], 'region_ids'],
    'region as text' => [['region_ids' => ['CENTRE']], 'region_ids.0'],
    'unknown region' => [['region_ids' => [99999]], 'region_ids', 'Région inconnue ou inactive.'],
]);

it('refuses to copy into a closed day, even with nothing to copy', function () {
    $this->calendar->close($this->day);
    $this->actingAs($this->adv);

    expectProblem($this->postJson($this->url, ['source_day' => '2026-10-07', 'mode' => 'FILL', 'region_ids' => [$this->centre->id]]), 409, 'DAY_NOT_OPEN');
});
