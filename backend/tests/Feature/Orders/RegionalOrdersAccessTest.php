<?php

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Orders\ClientOrders;
use App\Events\CutoffExtended;
use App\Events\DayClosed;
use App\Events\DistributionPublished;
use App\Events\LimitDraftsSaved;
use App\Events\OrderCancelled;
use App\Events\OrdersExpired;
use App\Events\OrderSubmitted;
use App\Events\OrderUpdated;
use App\Events\QuotaDraftsSaved;
use App\Events\QuotasPublished;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;

// Droits sur les commandes reçues (contrat du lot 5, §2 et §4) : rôle
// Régional et order.view ; l'ADV, l'Admin et le client reçoivent 403 ;
// 403 avant 404 avant 422 ; périmètre (titulaire, suppléant dans sa
// période) à la date de la journée demandée pour la liste, à la date de la
// commande pour le détail ; hors périmètre ou identifiant mal formé → 404.

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:30 à Alger : commande jusqu'à 13:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    Event::fake([
        QuotasPublished::class, QuotaDraftsSaved::class, LimitDraftsSaved::class, DistributionPublished::class, CutoffExtended::class,
        DayClosed::class, OrderSubmitted::class, OrderUpdated::class, OrderCancelled::class, OrdersExpired::class,
    ]);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    $this->regional = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['user_id' => $this->regional->id, 'region_id' => $this->centre->id]);
    $this->regionalEst = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['user_id' => $this->regionalEst->id, 'region_id' => $this->est->id]);

    $this->a = Client::factory()->create(['code' => 'C0012', 'region_id' => $this->centre->id]);
    $this->e = Client::factory()->create(['code' => 'C0300', 'region_id' => $this->est->id]);
    $this->accountA = userWithRole(Role::Client, ['client_id' => $this->a->id]);
    $this->accountE = userWithRole(Role::Client, ['client_id' => $this->e->id]);
    $this->orange = Product::factory()->create(['sku' => 'P0042']);
    $this->open = function (string $date): void {
        openOrderingDay($date, $this->centre, [[$this->orange, 500]]);
        openOrderingDay($date, $this->est, [[$this->orange, 200]]);
    };
    $this->send = fn (User $account, string $date = '2026-10-08') => app(ClientOrders::class)->submit(
        $account, (string) Str::uuid(), BusinessClock::parseDay($date), [['product_id' => $this->orange->id, 'quantity' => 10]], null,
    )['order'];
    ($this->open)('2026-10-08');
    $this->orderA = ($this->send)($this->accountA);
    $this->orderE = ($this->send)($this->accountE);

    $this->users = fn (string $role): User => match ($role) {
        'regional' => $this->regional,
        'adv' => userWithRole(Role::Adv),
        'admin' => admin(),
        'client' => $this->accountA,
        'none' => User::factory()->create(),
    };
    $this->call = fn (string $endpoint, ?string $id = null, string $query = '') => match ($endpoint) {
        'index' => $this->getJson('/api/v1/regional/orders'.$query),
        'show' => $this->getJson('/api/v1/regional/orders/'.($id ?? $this->orderA->public_id)),
    };
    $this->ids = fn (string $query = '') => $this->getJson('/api/v1/regional/orders'.$query)->assertOk()->json('data.*.id');
    // Suppléant de l'Est sur une période (« AAAA-MM-JJ »).
    $this->deputy = fn (string $from, string $to) => tap(userWithRole(Role::Regional), fn (User $user) => RegionAssignment::factory()
        ->deputy($from, $to)
        ->create(['user_id' => $user->id, 'region_id' => $this->est->id]));
});

describe('rights', function () {
    it('lets the regional of the region list and read the orders', function () {
        $this->actingAs(($this->users)('regional'));

        ($this->call)('index')->assertOk()->assertJsonPath('data.0.id', $this->orderA->public_id);
        ($this->call)('show')->assertOk()->assertJsonPath('id', $this->orderA->public_id);
    });

    it('refuses with 403 the ADV, the Admin, a client account and an account without role', function (string $role) {
        $this->actingAs(($this->users)($role));

        expectProblem(($this->call)('index'), 403, 'FORBIDDEN');
        expectProblem(($this->call)('show'), 403, 'FORBIDDEN');
    })->with(['adv', 'admin', 'client', 'none']);

    it('requires an authenticated session', function (string $endpoint) {
        expectProblem(($this->call)($endpoint), 401, 'UNAUTHENTICATED');
    })->with(['index', 'show']);

    it('follows the order.view permission of the regional role, not only the role', function () {
        SpatieRole::findByName(Role::Regional->value)->revokePermissionTo(Permission::OrderView->value);
        $this->actingAs(($this->users)('regional'));

        expectProblem(($this->call)('index'), 403, 'FORBIDDEN');
        expectProblem(($this->call)('show'), 403, 'FORBIDDEN');
    });

    it('checks the rights before the perimeter and the format (403 before 404 before 422)', function (string $role) {
        $this->actingAs(($this->users)($role));

        expectProblem(($this->call)('index', query: '?day=08/10/2026'), 403, 'FORBIDDEN');
        expectProblem(($this->call)('index', query: '?filter[region]='.$this->est->id.'&filter[status]=DRAFT'), 403, 'FORBIDDEN');
        expectProblem(($this->call)('show', 'not-an-ulid'), 403, 'FORBIDDEN');
        expectProblem(($this->call)('show', $this->orderE->public_id), 403, 'FORBIDDEN');
    })->with(['adv', 'admin', 'client']);

    it('answers 404 for a region out of the perimeter before the format of the other parameters', function () {
        $this->actingAs(($this->users)('regional'));

        expectProblem(($this->call)('index', query: '?filter[region]='.$this->est->id.'&filter[status]=DRAFT'), 404, 'NOT_FOUND');
        expectProblem(($this->call)('index', query: '?filter[region]='.$this->est->id.'&sort=submitted_at'), 404, 'NOT_FOUND');
        expectProblem(($this->call)('index', query: '?filter[region]=999999'), 404, 'NOT_FOUND');
        // Sa propre région : le format est contrôlé ensuite.
        expectProblem(($this->call)('index', query: '?filter[region]='.$this->centre->id.'&filter[status]=DRAFT'), 422, 'VALIDATION_FAILED');
    });

    it('answers 404 for a malformed order id without querying the orders', function (string $id) {
        $this->actingAs(($this->users)('regional'));
        ($this->call)('show')->assertOk(); // Réchauffe les caches (permissions, paramètres).

        DB::flushQueryLog();
        DB::enableQueryLog();
        expectProblem(($this->call)('show', $id), 404, 'NOT_FOUND');
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        expect($queries)->not->toBeEmpty()
            ->and($queries->filter(fn (string $query) => str_contains($query, '"orders"'))->all())->toBe([]);
    })->with([
        'pas un ULID' => ['not-an-ulid'],
        '25 caractères' => ['01JA2Z6K8QW3X9M4T7RBN5CDE'],
        'lettre exclue (U)' => ['01JA2Z6K8QW3X9M4T7RBN5CDEU'],
        'numéro de commande' => ['RMK-261008-00001'],
    ]);

    it('answers 404 for an unknown order, and finds an order whatever the case of its id (I-56)', function () {
        $this->actingAs(($this->users)('regional'));

        expectProblem(($this->call)('show', strtoupper((string) Str::ulid())), 404, 'NOT_FOUND');
        ($this->call)('show', strtolower($this->orderA->public_id))->assertOk()->assertJsonPath('id', $this->orderA->public_id);
    });
});

describe('perimeter', function () {
    it('keeps the orders of another region out of the list and answers 404 on their detail', function () {
        $this->actingAs($this->regional);

        expect(($this->ids)())->toBe([$this->orderA->public_id])
            ->and(($this->ids)('?filter[region]='.$this->centre->id))->toBe([$this->orderA->public_id]);
        expectProblem(($this->call)('show', $this->orderE->public_id), 404, 'NOT_FOUND');
        ($this->call)('show', $this->orderE->public_id)->assertJsonPath('detail', 'Commande introuvable.');

        $this->actingAs($this->regionalEst);
        expect(($this->ids)())->toBe([$this->orderE->public_id]);
        expectProblem(($this->call)('show'), 404, 'NOT_FOUND');
    });

    it('lists every region of the perimeter, and narrows the list and its counts to the filtered region', function () {
        RegionAssignment::factory()->create(['user_id' => $this->regional->id, 'region_id' => $this->est->id]);
        $this->actingAs($this->regional);

        expect(($this->ids)())->toBe([$this->orderA->public_id, $this->orderE->public_id])
            ->and(($this->ids)('?filter[region]='.$this->est->id))->toBe([$this->orderE->public_id]);
        ($this->call)('index', query: '?filter[region]='.$this->est->id)->assertJsonPath('meta.counts.SUBMITTED', 1);
        ($this->call)('index')->assertJsonPath('meta.counts.SUBMITTED', 2);
        ($this->call)('show', $this->orderE->public_id)->assertOk();
    });

    it('lets a deputy read the orders of the region during his period only, and never after a revocation (§3.4)', function () {
        $this->actingAs(($this->deputy)('2026-10-07', '2026-10-09'));
        expect(($this->ids)())->toBe([$this->orderE->public_id]);
        ($this->call)('show', $this->orderE->public_id)->assertOk();
        expectProblem(($this->call)('show'), 404, 'NOT_FOUND');

        $this->actingAs(($this->deputy)('2026-10-10', '2026-10-15'));
        expect(($this->ids)())->toBe([]);
        expectProblem(($this->call)('show', $this->orderE->public_id), 404, 'NOT_FOUND');

        $revoked = ($this->deputy)('2026-10-07', '2026-10-09');
        RegionAssignment::query()->where('user_id', $revoked->id)->update(['revoked_at' => now()]);
        $this->actingAs($revoked);
        expect(($this->ids)())->toBe([]);
        expectProblem(($this->call)('show', $this->orderE->public_id), 404, 'NOT_FOUND');
    });

    it('reads the perimeter on the day of the order, not on the day of the request', function () {
        // Dimanche 11 : répartition envoyée la veille, une commande de l'Est.
        ($this->open)('2026-10-11');
        $sunday = ($this->send)($this->accountE, '2026-10-11');
        // Suppléant de l'Est pour le dimanche seulement : rien aujourd'hui.
        $this->actingAs(($this->deputy)('2026-10-11', '2026-10-11'));

        ($this->call)('show', $sunday->public_id)->assertOk()->assertJsonPath('day', '2026-10-11');
        expect(($this->ids)('?day=2026-10-11'))->toBe([$sunday->public_id])
            ->and(($this->ids)())->toBe([]);
        expectProblem(($this->call)('show', $this->orderE->public_id), 404, 'NOT_FOUND');
        expectProblem(($this->call)('index', query: '?filter[region]='.$this->est->id), 404, 'NOT_FOUND');
        ($this->call)('index', query: '?day=2026-10-11&filter[region]='.$this->est->id)->assertOk();
    });

    it('keeps reading the orders of a past period after it ended', function () {
        // Le suppléant l'était jusqu'à hier : la commande d'hier reste lisible, celle d'aujourd'hui non.
        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00', 'Africa/Algiers'));
        ($this->open)('2026-10-07');
        $yesterday = ($this->send)($this->accountE, '2026-10-07');
        $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
        $this->actingAs(($this->deputy)('2026-10-01', '2026-10-07'));

        ($this->call)('show', $yesterday->public_id)->assertOk();
        expect(($this->ids)('?day=2026-10-07&filter[status]=SUBMITTED,EXPIRED'))->toBe([$yesterday->public_id]);
        expectProblem(($this->call)('show', $this->orderE->public_id), 404, 'NOT_FOUND');
        expect(($this->ids)())->toBe([]);
    });

    it('keeps an order in the region frozen at its submission when the client changes region (RG-S04)', function () {
        $this->e->forceFill(['region_id' => $this->centre->id])->save();

        $this->actingAs($this->regional);
        expect(($this->ids)())->toBe([$this->orderA->public_id]);
        expectProblem(($this->call)('show', $this->orderE->public_id), 404, 'NOT_FOUND');
        $this->actingAs($this->regionalEst);
        ($this->call)('show', $this->orderE->public_id)->assertOk()->assertJsonPath('region.code', 'EST');
        expect(Order::query()->whereKey($this->orderE->id)->value('region_id'))->toBe($this->est->id);
    });
});
