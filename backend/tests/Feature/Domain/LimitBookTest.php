<?php

use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Domain\Days\RegionDayStatus;
use App\Domain\Limits\LimitBook;
use App\Domain\Quotas\QuotaBook;
use App\Domain\Settings\Settings;
use App\Events\DistributionPublished;
use App\Events\LimitDraftsSaved;
use App\Events\QuotaDraftsSaved;
use App\Events\QuotasPublished;
use App\Http\Problems\ApiException;
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
use Illuminate\Validation\ValidationException;

// Bornes client et répartition (§5.3, RG-B01 à RG-B09).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 08:30', 'Africa/Algiers'));
    Event::fake([QuotasPublished::class, QuotaDraftsSaved::class, LimitDraftsSaved::class, DistributionPublished::class]);
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->est = Region::factory()->create(['code' => 'EST']);
    $this->regional = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['region_id' => $this->centre->id, 'user_id' => $this->regional->id]);

    $this->a = Client::factory()->create(['code' => 'C0012', 'region_id' => $this->centre->id]);
    $this->b = Client::factory()->create(['code' => 'C0047', 'region_id' => $this->centre->id]);
    $this->blocked = Client::factory()->blocked()->create(['code' => 'C0050', 'region_id' => $this->centre->id]);
    $this->elsewhere = Client::factory()->create(['code' => 'C0099', 'region_id' => $this->est->id]);
    $this->orange = Product::factory()->create(['sku' => 'P0042']);
    $this->pomme = Product::factory()->create(['sku' => 'P0057']);
    $this->lben = Product::factory()->create(['sku' => 'P0103']);

    $this->calendar = app(DayCalendar::class);
    $this->quotas = app(QuotaBook::class);
    $this->book = app(LimitBook::class);
    $this->open = function (string $date, array $quantities) {
        $day = $this->calendar->open(BusinessClock::parseDay($date), $this->adv);
        $cells = [];
        foreach ($quantities as [$product, $quantity]) {
            $cells[] = ['region_id' => $this->centre->id, 'product_id' => $product->id, 'quantity' => $quantity];
        }
        $this->quotas->saveDrafts($day, $cells, $this->adv);
        $this->quotas->publish($day, [$this->centre->id], $this->adv);

        return RegionDay::query()->where('business_day_id', $day->id)->where('region_id', $this->centre->id)->sole();
    };
    $this->regionDay = ($this->open)('2026-10-08', [[$this->orange, 500], [$this->pomme, 300]]);
    $this->cell = fn (Client $client, Product $product, ?int $limit) => ['client_id' => $client->id, 'product_id' => $product->id, 'limit' => $limit];
    $this->limit = fn (Client $client, Product $product, ?RegionDay $regionDay = null) => ClientLimit::query()
        ->where('region_day_id', ($regionDay ?? $this->regionDay)->id)->where('client_id', $client->id)->where('product_id', $product->id)->first();
});

it('saves limits as drafts and moves the region to « répartition en cours » (RG-B09, §6.1)', function () {
    $changed = $this->book->saveDrafts($this->regionDay, [
        ($this->cell)($this->a, $this->orange, 50),
        ($this->cell)($this->b, $this->orange, 0),
        ($this->cell)($this->b, $this->pomme, null),
    ], $this->regional);

    expect($changed)->toBe(2)
        ->and(($this->limit)($this->a, $this->orange))->has_draft->toBeTrue()->draft_max_qty->toBe(50)->max_qty->toBeNull()
        // 0 : produit interdit à ce client (RG-B01) ; « pas de borne » sans borne publiée : rien à enregistrer.
        ->and(($this->limit)($this->b, $this->orange)->draft_max_qty)->toBe(0)
        ->and(($this->limit)($this->b, $this->pomme))->toBeNull()
        ->and($this->regionDay->fresh()->status)->toBe(RegionDayStatus::DistributionDraft);
    Event::assertDispatched(LimitDraftsSaved::class);
});

it('refuses clients outside the region, products without quota, limits above the quota (RG-B02, RG-B03)', function () {
    try {
        $this->book->saveDrafts($this->regionDay, [
            ($this->cell)($this->elsewhere, $this->orange, 10),
            ($this->cell)($this->a, $this->lben, 10),
            ($this->cell)($this->a, $this->orange, 501),
        ], $this->regional);
        $this->fail('Bornes invalides acceptées.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe([
            'cells.0.client_id' => ['Client hors de la région ce jour-là.'],
            'cells.1.product_id' => ['Produit sans quota publié dans la région ce jour-là.'],
            'cells.2.limit' => ['La borne ne peut pas dépasser le quota de la région (500).'],
        ]);
    }
    expect(ClientLimit::query()->count())->toBe(0);
});

it('waits for the quotas of the region, and stops at the closing (RG-B06, §4.4)', function () {
    $tomorrow = $this->calendar->open(BusinessClock::parseDay('2026-10-09'), $this->adv);
    $draftDay = RegionDay::query()->where('business_day_id', $tomorrow->id)->where('region_id', $this->centre->id)->sole();
    expect(fn () => $this->book->saveDrafts($draftDay, [($this->cell)($this->a, $this->orange, 5)], $this->regional))
        ->toThrow(ApiException::class, 'Les quotas de la région CENTRE du 09/10/2026 ne sont pas encore publiés.');

    $this->calendar->close($this->regionDay->businessDay);
    expect(fn () => $this->book->saveDrafts($this->regionDay, [($this->cell)($this->a, $this->orange, 5)], $this->regional))
        ->toThrow(ApiException::class);
});

it('answers not found to a regional of another region', function () {
    $other = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['region_id' => $this->est->id, 'user_id' => $other->id]);

    expect(fn () => $this->book->saveDrafts($this->regionDay, [($this->cell)($this->a, $this->orange, 5)], $other))
        ->toThrow(ApiException::class);
});

it('publishes the distribution, notifies the active clients and journals it (RG-B06)', function () {
    $this->book->saveDrafts($this->regionDay, [($this->cell)($this->a, $this->orange, 300), ($this->cell)($this->b, $this->orange, 250)], $this->regional);

    $result = $this->book->publish($this->regionDay, $this->regional);

    $regionDay = $this->regionDay->fresh();
    expect($result->toArray())->toBe([
        'version' => 1,
        'first' => true,
        'changes' => 2,
        // Client bloqué non notifié ; client d'une autre région non plus.
        'notified_clients' => 2,
        // Surallocation autorisée et signalée (RG-B05) : 550 > 500.
        'overallocated' => [['product_id' => $this->orange->id, 'sku' => 'P0042', 'quota' => 500, 'sum_limits' => 550]],
    ])
        ->and($regionDay->status)->toBe(RegionDayStatus::DistributionPublished)
        ->and($regionDay->distribution_published_by)->toBe($this->regional->id)
        ->and(($this->limit)($this->a, $this->orange))->max_qty->toBe(300)->has_draft->toBeFalse()
        ->and(AuditLog::query()->where('log_name', 'limits')->where('event', 'published')->first()->properties['changes_count'])->toBe(2);
    Event::assertDispatched(DistributionPublished::class, fn (DistributionPublished $e) => $e->first && $e->version === 1
        && $e->notifiedClientIds === [$this->a->id, $this->b->id]);
});

it('sends a distribution without any limit, and notifies only clients with a limit in REQUIRED policy (RG-B04)', function () {
    $result = $this->book->publish($this->regionDay, $this->regional);
    expect($result->notifiedClientIds)->toBe([$this->a->id, $this->b->id]);

    app(Settings::class)->update(['limits' => ['no_limit_policy' => 'REQUIRED']], null);
    $next = ($this->open)('2026-10-09', [[$this->orange, 100]]);
    $this->book->saveDrafts($next, [($this->cell)($this->b, $this->orange, 10)], $this->regional);
    expect($this->book->publish($next, $this->regional)->notifiedClientIds)->toBe([$this->b->id]);
});

it('previews the sending exactly as publish() will do it (EF-REG-02)', function () {
    $this->book->saveDrafts($this->regionDay, [($this->cell)($this->a, $this->orange, 300), ($this->cell)($this->b, $this->orange, 250)], $this->regional);

    expect($this->book->previewPublication($this->regionDay))->toBe([
        'first' => true,
        'drafts' => 2,
        'notified_clients' => 2,
        'overallocated' => [['product_id' => $this->orange->id, 'sku' => 'P0042', 'quota' => 500, 'sum_limits' => 550]],
    ]);
    $this->book->publish($this->regionDay, $this->regional);

    // Modifications : seuls les clients impactés ; brouillon 0 sur un client.
    $this->book->saveDrafts($this->regionDay, [($this->cell)($this->b, $this->orange, 0), ($this->cell)($this->b, $this->pomme, 10)], $this->regional);
    expect($this->book->previewPublication($this->regionDay))->toBe([
        'first' => false,
        'drafts' => 2,
        'notified_clients' => 1,
        'overallocated' => [],
    ]);

    // Politique OBLIGATOIRE : seuls les clients avec une borne > 0 affichée.
    app(Settings::class)->update(['limits' => ['no_limit_policy' => 'REQUIRED']], null);
    $next = ($this->open)('2026-10-09', [[$this->orange, 100]]);
    $this->book->saveDrafts($next, [($this->cell)($this->a, $this->orange, 0), ($this->cell)($this->b, $this->orange, 10)], $this->regional);
    expect($this->book->previewPublication($next)['notified_clients'])->toBe(1)
        ->and($this->book->publish($next, $this->regional)->notifiedClientIds)->toBe([$this->b->id]);
});

it('notifies only the impacted clients when modified limits are sent, and refuses an empty sending (RG-B07)', function () {
    $this->book->saveDrafts($this->regionDay, [($this->cell)($this->a, $this->orange, 300), ($this->cell)($this->b, $this->orange, 100)], $this->regional);
    $this->book->publish($this->regionDay, $this->regional);
    expect(fn () => $this->book->publish($this->regionDay, $this->regional))->toThrow(ApiException::class, 'Aucune modification à envoyer.');

    // Valeur reprise à l'identique : plus de brouillon.
    $this->book->saveDrafts($this->regionDay, [($this->cell)($this->b, $this->orange, 120), ($this->cell)($this->a, $this->orange, 300)], $this->regional);
    expect(($this->limit)($this->a, $this->orange)->has_draft)->toBeFalse();

    $result = $this->book->publish($this->regionDay, $this->regional);
    expect($result->version)->toBe(2)->and($result->first)->toBeFalse()->and($result->notifiedClientIds)->toBe([$this->b->id]);
});

it('removes a published limit when « pas de borne » is sent', function () {
    $this->book->saveDrafts($this->regionDay, [($this->cell)($this->a, $this->orange, 300)], $this->regional);
    $this->book->publish($this->regionDay, $this->regional);
    $this->book->saveDrafts($this->regionDay, [($this->cell)($this->a, $this->orange, null)], $this->regional);

    expect(($this->limit)($this->a, $this->orange))->has_draft->toBeTrue()->draft_max_qty->toBeNull();
    $this->book->publish($this->regionDay, $this->regional);
    expect(($this->limit)($this->a, $this->orange))->max_qty->toBeNull()->has_draft->toBeFalse();
});

it('refuses the whole sending when a limit exceeds a quota decreased since (RG-B03, RG-Q06)', function () {
    $this->book->saveDrafts($this->regionDay, [($this->cell)($this->a, $this->orange, 400), ($this->cell)($this->b, $this->pomme, 50)], $this->regional);
    $this->quotas->saveDrafts($this->regionDay->businessDay, [['region_id' => $this->centre->id, 'product_id' => $this->orange->id, 'quantity' => 350]], $this->adv);
    $this->quotas->publish($this->regionDay->businessDay, [$this->centre->id], $this->adv);

    try {
        $this->book->publish($this->regionDay, $this->regional);
        $this->fail('Envoi accepté au-dessus du quota.');
    } catch (ApiException $e) {
        expect($e->extra['violations'])->toBe([['client_id' => $this->a->id, 'client' => 'C0012', 'product_id' => $this->orange->id, 'sku' => 'P0042', 'requested' => 400, 'quota' => 350]]);
    }
    expect(($this->limit)($this->b, $this->pomme)->max_qty)->toBeNull()
        ->and($this->regionDay->fresh()->distribution_version)->toBe(0);
});

it('takes back the limits of the last distribution sent, capped to the quota of the day (RG-B08)', function () {
    $this->book->saveDrafts($this->regionDay, [
        ($this->cell)($this->a, $this->orange, 400),
        ($this->cell)($this->b, $this->orange, 100),
        ($this->cell)($this->a, $this->pomme, 50),
    ], $this->regional);
    $this->book->publish($this->regionDay, $this->regional);

    // Lendemain : orange réduit à 300, pomme sans quota ; B a déjà une borne saisie.
    $this->travelTo(CarbonImmutable::parse('2026-10-09 07:00', 'Africa/Algiers'));
    $next = ($this->open)('2026-10-09', [[$this->orange, 300], [$this->lben, 200]]);
    $this->book->saveDrafts($next, [($this->cell)($this->b, $this->orange, 20)], $this->regional);

    expect($this->book->copyPrevious($next, false, $this->regional))->toBe(['changed' => 1, 'source_day' => '2026-10-08'])
        ->and(($this->limit)($this->a, $this->orange, $next)->draft_max_qty)->toBe(300)
        ->and(($this->limit)($this->b, $this->orange, $next)->draft_max_qty)->toBe(20)
        ->and(($this->limit)($this->a, $this->pomme, $next))->toBeNull();

    // Écraser : B reprend 100, la cellule lben sans valeur reprise reste sans borne.
    expect($this->book->copyPrevious($next, true, $this->regional)['changed'])->toBe(1)
        ->and(($this->limit)($this->b, $this->orange, $next)->draft_max_qty)->toBe(100);
});

it('refuses « Reprendre J-1 » when no distribution was ever sent', function () {
    expect(fn () => $this->book->copyPrevious($this->regionDay, true, $this->regional))->toThrow(ValidationException::class);
});

it('follows the region change of a client on its effective day (RG-S04)', function () {
    $this->elsewhere->forceFill(['next_region_id' => $this->centre->id, 'region_change_on' => '2026-10-09'])->save();

    expect($this->book->clientsQuery($this->regionDay)->pluck('code')->all())->toBe(['C0012', 'C0047', 'C0050']);
    $next = ($this->open)('2026-10-09', [[$this->orange, 300]]);
    expect($this->book->clientsQuery($next)->pluck('code')->all())->toBe(['C0012', 'C0047', 'C0050', 'C0099']);
});
