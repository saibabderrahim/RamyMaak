<?php

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Domain\Days\RegionDayStatus;
use App\Domain\Quotas\QuotaBook;
use App\Events\QuotaDraftsSaved;
use App\Events\QuotasPublished;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionDay;
use App\Models\RegionQuota;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

// Publication : POST /adv/days/{day}/quotas/publish (RG-Q04 à RG-Q08, contrat du lot 3 §4).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 07:30', 'Africa/Algiers'));
    Event::fake([QuotasPublished::class, QuotaDraftsSaved::class]);
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->est = Region::factory()->create(['code' => 'EST']);
    $this->orange = Product::factory()->create(['sku' => 'P0042']);
    $this->pomme = Product::factory()->create(['sku' => 'P0057']);
    $this->calendar = app(DayCalendar::class);
    $this->book = app(QuotaBook::class);
    $this->day = $this->calendar->open(BusinessClock::parseDay('2026-10-08'), $this->adv);
    $this->url = '/api/v1/adv/days/2026-10-08/quotas/publish';
    $this->cell = fn (Region $region, Product $product, ?int $quantity) => ['region_id' => $region->id, 'product_id' => $product->id, 'quantity' => $quantity];
    $this->quota = fn (Region $region, Product $product) => RegionQuota::query()
        ->where('product_id', $product->id)
        ->whereHas('regionDay', fn ($q) => $q->where('business_day_id', $this->day->id)->where('region_id', $region->id))
        ->first();
    $this->regionDay = fn (Region $region) => RegionDay::query()->where('business_day_id', $this->day->id)->where('region_id', $region->id)->sole();
});

it('requires quota.publish and the back-office before validating the body', function (Closure $actor) {
    $this->book->saveDrafts($this->day, [($this->cell)($this->centre, $this->orange, 500)], $this->adv);
    $this->actingAs($actor());

    // 403 et non 422, même pour un corps invalide.
    expectProblem($this->postJson($this->url, []), 403, 'FORBIDDEN');
    expectProblem($this->postJson($this->url, ['all' => true]), 403, 'FORBIDDEN');
    expect(($this->regionDay)($this->centre)->quota_version)->toBe(0);
})->with([
    'Admin (lecture seule)' => [fn () => admin()],
    'Admin doté de quota.edit' => [function () {
        $admin = admin();
        $admin->givePermissionTo(Permission::QuotaEdit->value);

        return $admin;
    }],
    'Régional' => [fn () => userWithRole(Role::Regional)],
    'Régional doté de quota.publish' => [function () {
        $regional = userWithRole(Role::Regional);
        $regional->givePermissionTo(Permission::QuotaPublish->value);

        return $regional;
    }],
    'Client' => [fn () => userWithRole(Role::Client)],
    'Sans rôle' => [fn () => User::factory()->create()],
]);

it('lets an Admin granted quota.publish publish', function () {
    $this->book->saveDrafts($this->day, [($this->cell)($this->centre, $this->orange, 500)], $this->adv);
    $admin = admin();
    $admin->givePermissionTo(Permission::QuotaPublish->value);
    $this->actingAs($admin);

    $this->postJson($this->url, ['region_ids' => [$this->centre->id]])
        ->assertOk()
        ->assertJsonPath('published.0.code', 'CENTRE');
});

it('publishes the chosen regions, first publication then modifications (RG-Q04, RG-Q08)', function () {
    $this->book->saveDrafts($this->day, [
        ($this->cell)($this->centre, $this->orange, 500),
        ($this->cell)($this->centre, $this->pomme, 200),
        ($this->cell)($this->est, $this->orange, 300),
    ], $this->adv);
    $this->actingAs($this->adv);

    $this->postJson($this->url, ['region_ids' => [$this->centre->id]])
        ->assertOk()
        ->assertExactJson([
            'published' => [['region_id' => $this->centre->id, 'code' => 'CENTRE', 'version' => 1, 'first' => true, 'changes' => 2]],
            'skipped' => [],
        ]);

    expect(($this->quota)($this->centre, $this->orange)->only(['quantity', 'draft_quantity']))->toBe(['quantity' => 500, 'draft_quantity' => null])
        ->and(($this->regionDay)($this->centre)->status)->toBe(RegionDayStatus::QuotaPublished)
        // La région non choisie garde son brouillon.
        ->and(($this->quota)($this->est, $this->orange)->only(['quantity', 'draft_quantity']))->toBe(['quantity' => 0, 'draft_quantity' => 300])
        ->and(($this->regionDay)($this->est)->status)->toBe(RegionDayStatus::QuotaDraft);
    Event::assertDispatched(QuotasPublished::class, fn (QuotasPublished $e) => $e->regionDayId === ($this->regionDay)($this->centre)->id
        && $e->version === 1 && $e->first && $e->changedProducts === 2 && $e->actorId === $this->adv->id);

    // Modification après publication (N-02).
    $this->book->saveDrafts($this->day, [($this->cell)($this->centre, $this->orange, 450)], $this->adv);
    $this->postJson($this->url, ['region_ids' => [$this->centre->id]])
        ->assertOk()
        ->assertJsonPath('published', [['region_id' => $this->centre->id, 'code' => 'CENTRE', 'version' => 2, 'first' => false, 'changes' => 1]]);

    expect(AuditLog::query()->where('log_name', 'quotas')->orderBy('id')->pluck('event')->all())->toBe(['published', 'updated']);
    Event::assertDispatched(QuotasPublished::class, fn (QuotasPublished $e) => $e->version === 2 && ! $e->first && $e->changedProducts === 1);
});

it('publishes every region of the perimeter with all, leaving aside the others with their reason', function () {
    $sud = Region::factory()->create(['code' => 'SUD']);
    $ouest = Region::factory()->create(['code' => 'OUEST']);
    $this->book->saveDrafts($this->day, [
        ($this->cell)($this->centre, $this->orange, 500),
        ($this->cell)($sud, $this->orange, 80),
        ($this->cell)($ouest, $this->orange, 40),
    ], $this->adv);
    $this->book->publish($this->day, [$sud->id], $this->adv);
    // Journée régionale déjà close (ex. région arrêtée) : écartée.
    RegionDay::query()->where('region_id', $ouest->id)->update(['status' => RegionDayStatus::Closed->value]);
    $this->actingAs($this->adv);

    $response = $this->postJson($this->url, ['all' => true])
        ->assertOk()
        ->assertJsonPath('published', [['region_id' => $this->centre->id, 'code' => 'CENTRE', 'version' => 1, 'first' => true, 'changes' => 1]]);

    expect(collect($response->json('skipped'))->sortBy('code')->values()->all())->toBe([
        ['region_id' => $this->est->id, 'code' => 'EST', 'reason' => 'EMPTY'],
        ['region_id' => $ouest->id, 'code' => 'OUEST', 'reason' => 'CLOSED'],
        ['region_id' => $sud->id, 'code' => 'SUD', 'reason' => 'NO_CHANGES'],
    ])
        ->and(($this->regionDay)($this->est)->quota_version)->toBe(0)
        ->and(($this->quota)($ouest, $this->orange)->draft_quantity)->toBe(40);
});

it('publishes only the regions of a restricted ADV, and refuses the others (RG-S05)', function () {
    $this->book->saveDrafts($this->day, [
        ($this->cell)($this->centre, $this->orange, 500),
        ($this->cell)($this->est, $this->orange, 300),
    ], $this->adv);
    $restricted = userWithRole(Role::Adv);
    RegionAssignment::factory()->adv()->create(['user_id' => $restricted->id, 'region_id' => $this->est->id]);
    $this->actingAs($restricted);

    $this->postJson($this->url, ['region_ids' => [$this->est->id, $this->centre->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['region_ids' => 'Région hors de votre périmètre.']);
    expect(($this->regionDay)($this->est)->quota_version)->toBe(0);

    $this->postJson($this->url, ['all' => true])
        ->assertOk()
        ->assertExactJson([
            'published' => [['region_id' => $this->est->id, 'code' => 'EST', 'version' => 1, 'first' => true, 'changes' => 1]],
            'skipped' => [],
        ]);
    expect(($this->regionDay)($this->centre)->quota_version)->toBe(0);
});

it('refuses a first publication without any quota above 0, and publishes nothing then', function () {
    $this->book->saveDrafts($this->day, [($this->cell)($this->centre, $this->orange, 500)], $this->adv);
    $this->actingAs($this->adv);

    $this->postJson($this->url, ['region_ids' => [$this->centre->id, $this->est->id]])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonValidationErrors(['region_ids' => 'Région EST : aucun quota supérieur à 0 à publier.']);

    // Tout ou rien : le Centre n'est pas publié non plus.
    expect(($this->regionDay)($this->centre)->quota_version)->toBe(0)
        ->and(($this->quota)($this->centre, $this->orange)->draft_quantity)->toBe(500);
    Event::assertNotDispatched(QuotasPublished::class);
});

it('refuses to lower a quota under the validated quantity, with the violations, and publishes nothing (RG-Q05)', function () {
    $this->book->saveDrafts($this->day, [
        ($this->cell)($this->centre, $this->orange, 500),
        ($this->cell)($this->centre, $this->pomme, 200),
        ($this->cell)($this->est, $this->orange, 300),
    ], $this->adv);
    $this->book->publish($this->day, null, $this->adv);
    // Commandes déjà validées (lot 6), posées directement en base.
    RegionQuota::query()->where('product_id', $this->orange->id)->update(['validated_qty' => 300]);
    $this->book->saveDrafts($this->day, [
        ($this->cell)($this->centre, $this->orange, 250),
        ($this->cell)($this->centre, $this->pomme, 150),
        ($this->cell)($this->est, $this->orange, 350),
    ], $this->adv);
    $this->actingAs($this->adv);

    $response = $this->postJson($this->url, ['all' => true]);

    expectProblem($response, 409, 'QUOTA_BELOW_VALIDATED');
    $response->assertJsonPath('detail', 'Le quota ne peut pas descendre sous la quantité déjà validée.')
        ->assertJsonPath('violations', [[
            'region_id' => $this->centre->id, 'region' => 'CENTRE', 'product_id' => $this->orange->id,
            'sku' => 'P0042', 'validated' => 300, 'requested' => 250,
        ]]);

    expect(($this->quota)($this->centre, $this->pomme)->only(['quantity', 'draft_quantity']))->toBe(['quantity' => 200, 'draft_quantity' => 150])
        ->and(($this->quota)($this->est, $this->orange)->only(['quantity', 'draft_quantity']))->toBe(['quantity' => 300, 'draft_quantity' => 350])
        ->and(RegionDay::query()->pluck('quota_version')->all())->toBe([1, 1]);
});

it('drops the draft of a product deactivated since instead of publishing it (RG-Q02)', function () {
    $this->book->saveDrafts($this->day, [
        ($this->cell)($this->centre, $this->orange, 500),
        ($this->cell)($this->centre, $this->pomme, 200),
    ], $this->adv);
    $this->pomme->update(['is_active' => false]);
    $this->actingAs($this->adv);

    $this->postJson($this->url, ['region_ids' => [$this->centre->id]])
        ->assertOk()
        ->assertJsonPath('published.0.changes', 1);

    expect(($this->quota)($this->centre, $this->pomme)->only(['quantity', 'draft_quantity']))->toBe(['quantity' => 0, 'draft_quantity' => null]);
});

it('validates the choice of regions', function (array $payload, string $field, ?string $message = null) {
    $this->book->saveDrafts($this->day, [($this->cell)($this->centre, $this->orange, 500)], $this->adv);
    $this->actingAs($this->adv);

    $response = $this->postJson($this->url, $payload)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_FAILED');

    $message === null ? $response->assertJsonValidationErrors($field) : $response->assertJsonValidationErrors([$field => $message]);
    expect(($this->regionDay)($this->centre)->quota_version)->toBe(0);
})->with([
    'nothing chosen' => [[], 'region_ids', 'Choisissez des régions ou toutes les régions.'],
    'all false' => [['all' => false], 'region_ids', 'Choisissez des régions ou toutes les régions.'],
    'both' => [['all' => true, 'region_ids' => [1]], 'region_ids', 'Choisissez des régions ou toutes les régions, pas les deux.'],
    'all not a boolean' => [['all' => 'toutes'], 'all'],
    'empty region list' => [['region_ids' => []], 'region_ids'],
    'region list as text' => [['region_ids' => 'CENTRE'], 'region_ids'],
    'region list as object' => [['region_ids' => ['a' => 1]], 'region_ids'],
    'region as text' => [['region_ids' => ['CENTRE']], 'region_ids.0'],
    'unknown region' => [['region_ids' => [99999]], 'region_ids', 'Région inconnue ou inactive.'],
]);

it('refuses an inactive region', function () {
    $inactive = Region::factory()->inactive()->create();
    $this->actingAs($this->adv);

    $this->postJson($this->url, ['region_ids' => [$inactive->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['region_ids' => 'Région inconnue ou inactive.']);
});

it('refuses any publication once the day is closed', function () {
    $this->book->saveDrafts($this->day, [($this->cell)($this->centre, $this->orange, 500)], $this->adv);
    $this->calendar->close($this->day);
    $this->actingAs($this->adv);

    expectProblem($this->postJson($this->url, ['all' => true]), 409, 'DAY_NOT_OPEN');
    expect(($this->regionDay)($this->centre)->quota_version)->toBe(0);
});

it('answers 404 for a day that was not opened', function () {
    $this->actingAs($this->adv);

    expectProblem($this->postJson('/api/v1/adv/days/2026-10-09/quotas/publish', ['all' => true]), 404, 'NOT_FOUND');
});
