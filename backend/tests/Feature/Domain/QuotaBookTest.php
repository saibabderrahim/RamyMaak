<?php

use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Domain\Days\RegionDayStatus;
use App\Domain\Quotas\QuotaBook;
use App\Events\QuotaDraftsSaved;
use App\Events\QuotasPublished;
use App\Http\Problems\ApiException;
use App\Models\AuditLog;
use App\Models\BusinessDay;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionDay;
use App\Models\RegionQuota;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

// Quotas région : brouillon, copie, publication (§5.2, RG-Q01 à RG-Q08).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 07:30', 'Africa/Algiers'));
    Event::fake([QuotasPublished::class, QuotaDraftsSaved::class]);
    $this->book = app(QuotaBook::class);
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->est = Region::factory()->create(['code' => 'EST']);
    $this->orange = Product::factory()->create(['sku' => 'P0042']);
    $this->pomme = Product::factory()->create(['sku' => 'P0057']);
    $this->day = app(DayCalendar::class)->open(BusinessClock::parseDay('2026-10-08'), $this->adv);

    $this->cell = fn (Region $region, Product $product, ?int $quantity) => ['region_id' => $region->id, 'product_id' => $product->id, 'quantity' => $quantity];
    $this->quota = fn (Region $region, Product $product) => RegionQuota::query()
        ->where('product_id', $product->id)
        ->whereHas('regionDay', fn ($q) => $q->where('business_day_id', $this->day->id)->where('region_id', $region->id))
        ->first();
    $this->regionDay = fn (Region $region) => RegionDay::query()->where('business_day_id', $this->day->id)->where('region_id', $region->id)->sole();
});

it('saves drafts invisible until publication (RG-Q03)', function () {
    $changed = $this->book->saveDrafts($this->day, [
        ($this->cell)($this->centre, $this->orange, 500),
        ($this->cell)($this->centre, $this->pomme, 300),
        ($this->cell)($this->est, $this->orange, 0),
    ], $this->adv);

    $orange = ($this->quota)($this->centre, $this->orange);
    expect($changed)->toBe(2)
        ->and($orange->quantity)->toBe(0)
        ->and($orange->draft_quantity)->toBe(500)
        // 0 sur une région jamais publiée : rien à publier, aucune ligne créée.
        ->and(($this->quota)($this->est, $this->orange))->toBeNull()
        ->and(($this->regionDay)($this->centre)->status)->toBe(RegionDayStatus::QuotaDraft);
    Event::assertDispatchedTimes(QuotaDraftsSaved::class, 1);
    Event::assertDispatched(QuotaDraftsSaved::class, fn (QuotaDraftsSaved $e) => $e->regionIds === [$this->centre->id] && $e->businessDayId === $this->day->id);

    // Annuler un brouillon, ou revenir à la valeur publiée : plus rien en attente.
    $this->book->saveDrafts($this->day, [($this->cell)($this->centre, $this->orange, null)], $this->adv);
    expect(($this->quota)($this->centre, $this->orange)->draft_quantity)->toBeNull();
});

it('refuses inactive products, inactive regions and regions outside the ADV perimeter (RG-Q02, RG-S05)', function () {
    $inactiveProduct = Product::factory()->create(['is_active' => false]);
    $inactiveRegion = Region::factory()->create(['is_active' => false]);

    expect(fn () => $this->book->saveDrafts($this->day, [($this->cell)($this->centre, $inactiveProduct, 10)], $this->adv))->toThrow(ValidationException::class)
        ->and(fn () => $this->book->saveDrafts($this->day, [($this->cell)($inactiveRegion, $this->orange, 10)], $this->adv))->toThrow(ValidationException::class);

    $restricted = userWithRole(Role::Adv);
    RegionAssignment::factory()->adv()->create(['user_id' => $restricted->id, 'region_id' => $this->est->id]);
    try {
        $this->book->saveDrafts($this->day, [($this->cell)($this->centre, $this->orange, 10)], $restricted);
        $this->fail('Région hors périmètre acceptée.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['cells.0.region_id' => ['Région hors de votre périmètre.']]);
    }
    expect($this->book->saveDrafts($this->day, [($this->cell)($this->est, $this->orange, 10)], $restricted))->toBe(1);
});

it('publishes the drafts of the chosen regions, version 1 then 2, and journals before / after (RG-Q04, RG-Q08)', function () {
    $this->book->saveDrafts($this->day, [
        ($this->cell)($this->centre, $this->orange, 500),
        ($this->cell)($this->est, $this->orange, 300),
    ], $this->adv);

    $result = $this->book->publish($this->day, [$this->centre->id], $this->adv);

    expect($result->published)->toBe([['region_id' => $this->centre->id, 'code' => 'CENTRE', 'version' => 1, 'first' => true, 'changes' => 1]])
        ->and(($this->quota)($this->centre, $this->orange)->only(['quantity', 'draft_quantity']))->toBe(['quantity' => 500, 'draft_quantity' => null])
        ->and(($this->quota)($this->est, $this->orange)->draft_quantity)->toBe(300)
        ->and(($this->regionDay)($this->centre)->status)->toBe(RegionDayStatus::QuotaPublished)
        ->and(($this->regionDay)($this->est)->status)->toBe(RegionDayStatus::QuotaDraft);
    Event::assertDispatched(QuotasPublished::class, fn (QuotasPublished $e) => $e->first && $e->version === 1 && $e->changedProducts === 1);

    // Modification après publication (N-02) : version 2, statut inchangé.
    $this->book->saveDrafts($this->day, [($this->cell)($this->centre, $this->orange, 450)], $this->adv);
    $result = $this->book->publish($this->day, [$this->centre->id], $this->adv);

    $regionDay = ($this->regionDay)($this->centre);
    expect($result->published[0])->toMatchArray(['version' => 2, 'first' => false, 'changes' => 1])
        ->and($regionDay->quota_version)->toBe(2)
        ->and($regionDay->status)->toBe(RegionDayStatus::QuotaPublished)
        ->and($regionDay->quota_published_by)->toBe($this->adv->id);

    $entries = AuditLog::query()->where('log_name', 'quotas')->orderBy('id')->get();
    expect($entries->pluck('event')->all())->toBe(['published', 'updated'])
        ->and($entries[1]->properties['changes'])->toBe([['product_id' => $this->orange->id, 'sku' => 'P0042', 'old' => 500, 'new' => 450]]);
});

it('publishes all regions at once, leaving aside those with nothing to publish', function () {
    $sud = Region::factory()->create(['code' => 'SUD']);
    $this->book->saveDrafts($this->day, [($this->cell)($this->centre, $this->orange, 500)], $this->adv);

    $result = $this->book->publish($this->day, null, $this->adv);

    expect(array_column($result->published, 'code'))->toBe(['CENTRE'])
        ->and(collect($result->skipped)->pluck('reason', 'code')->all())->toBe(['EST' => 'EMPTY'])
        // Région créée après l'ouverture et sans quota : hors de la publication.
        ->and(RegionDay::query()->where('region_id', $sud->id)->exists())->toBeFalse();

    expect($this->book->publish($this->day, null, $this->adv)->skipped)->toContain(['region_id' => $this->centre->id, 'code' => 'CENTRE', 'reason' => 'NO_CHANGES']);
});

it('refuses a first publication without any quota above 0 for an explicitly chosen region', function () {
    expect(fn () => $this->book->publish($this->day, [$this->est->id], $this->adv))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()['region_ids'])->toBe(['Région EST : aucun quota supérieur à 0 à publier.']));
});

it('never lowers a quota under the validated quantity, and publishes nothing then (RG-Q05, §5.8)', function () {
    $this->book->saveDrafts($this->day, [
        ($this->cell)($this->centre, $this->orange, 500),
        ($this->cell)($this->centre, $this->pomme, 200),
    ], $this->adv);
    $this->book->publish($this->day, null, $this->adv);
    // Lot 6 : 300 déjà validés sur le jus d'orange.
    RegionQuota::query()->where('product_id', $this->orange->id)->update(['validated_qty' => 300]);

    $this->book->saveDrafts($this->day, [
        ($this->cell)($this->centre, $this->orange, 250),
        ($this->cell)($this->centre, $this->pomme, 150),
    ], $this->adv);

    try {
        $this->book->publish($this->day, [$this->centre->id], $this->adv);
        $this->fail('Diminution sous le validé publiée.');
    } catch (ApiException $e) {
        expect($e->errorCode->value)->toBe('QUOTA_BELOW_VALIDATED')
            ->and($e->extra['violations'])->toBe([[
                'region_id' => $this->centre->id, 'region' => 'CENTRE', 'product_id' => $this->orange->id,
                'sku' => 'P0042', 'validated' => 300, 'requested' => 250,
            ]]);
    }

    // Tout ou rien : la pomme n'a pas été publiée non plus.
    expect(($this->quota)($this->centre, $this->pomme)->only(['quantity', 'draft_quantity']))->toBe(['quantity' => 200, 'draft_quantity' => 150])
        ->and(($this->regionDay)($this->centre)->quota_version)->toBe(1);

    // Une hausse reste possible à tout moment.
    $this->book->saveDrafts($this->day, [($this->cell)($this->centre, $this->orange, 600)], $this->adv);
    expect($this->book->publish($this->day, [$this->centre->id], $this->adv)->published[0]['version'])->toBe(2);
});

it('copies the published quotas of another day, replacing or filling (EF-ADV-02)', function () {
    $previous = app(DayCalendar::class)->open(BusinessClock::parseDay('2026-10-07'), $this->adv);
    $this->book->saveDrafts($previous, [
        ($this->cell)($this->centre, $this->orange, 400),
        ($this->cell)($this->centre, $this->pomme, 100),
        ($this->cell)($this->est, $this->orange, 250),
    ], $this->adv);
    $this->book->publish($previous, null, $this->adv);

    $this->book->saveDrafts($this->day, [($this->cell)($this->centre, $this->orange, 999)], $this->adv);

    $this->book->copyFrom($this->day, $previous, [$this->centre->id], false, $this->adv);
    expect(($this->quota)($this->centre, $this->orange)->draft_quantity)->toBe(999)
        ->and(($this->quota)($this->centre, $this->pomme)->draft_quantity)->toBe(100)
        ->and(($this->quota)($this->est, $this->orange))->toBeNull();

    $this->book->copyFrom($this->day, $previous, null, true, $this->adv);
    expect(($this->quota)($this->centre, $this->orange)->draft_quantity)->toBe(400)
        ->and(($this->quota)($this->est, $this->orange)->draft_quantity)->toBe(250);
});

it('refuses any change once the day is closed', function () {
    app(DayCalendar::class)->close($this->day);

    expect(fn () => $this->book->saveDrafts(BusinessDay::find($this->day->id), [($this->cell)($this->centre, $this->orange, 10)], $this->adv))
        ->toThrow(fn (ApiException $e) => expect($e->errorCode->value)->toBe('DAY_NOT_OPEN'))
        ->and(fn () => $this->book->publish(BusinessDay::find($this->day->id), null, $this->adv))
        ->toThrow(fn (ApiException $e) => expect($e->errorCode->value)->toBe('DAY_NOT_OPEN'));
});
