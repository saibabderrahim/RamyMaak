<?php

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Orders\ClientOrders;
use App\Domain\Orders\OrderStatus;
use App\Domain\Settings\Settings;
use App\Events\CutoffExtended;
use App\Events\DayClosed;
use App\Events\DistributionPublished;
use App\Events\LimitDraftsSaved;
use App\Events\OrderCancelled;
use App\Events\OrderRejected;
use App\Events\OrdersExpired;
use App\Events\OrderSubmitted;
use App\Events\OrderUpdated;
use App\Events\OrderValidated;
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

// Droits sur la décision du régional (contrat du lot 6, §2 et §3) :
// POST /regional/orders/{id}/validate et /reject — rôle Régional et
// order.validate ; l'ADV, l'Admin et le client reçoivent 403 ; 401 puis 403
// avant 404 avant 422 ; périmètre (titulaire, suppléant dans sa période) à
// la date de la commande, région figée à l'envoi ; identifiant mal formé ou
// inconnu, ou hors périmètre → 404, sans effet.

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:30 à Alger : commande jusqu'à 13:00, validation jusqu'à 15:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    Event::fake([
        QuotasPublished::class, QuotaDraftsSaved::class, LimitDraftsSaved::class, DistributionPublished::class, CutoffExtended::class,
        DayClosed::class, OrderSubmitted::class, OrderUpdated::class, OrderCancelled::class, OrdersExpired::class, OrderValidated::class, OrderRejected::class,
    ]);
    app(Settings::class)->update(['orders' => ['max_per_day' => 3]], null);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    $this->regional = userWithRole(Role::Regional, ['name' => 'Karim Régional']);
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
    // Corps valide pour la commande A (une ligne, 10 demandés).
    $this->bodies = [
        'validate' => fn (Order $order) => ['version' => $order->version, 'lines' => [['product_id' => $this->orange->id, 'validated_qty' => 10]]],
        'reject' => fn (Order $order) => ['version' => $order->version, 'reason_code' => 'QUOTA_EPUISE'],
    ];
    $this->call = fn (string $action, ?string $id = null, ?array $body = null) => $this->postJson(
        '/api/v1/regional/orders/'.($id ?? $this->orderA->public_id).'/'.$action,
        $body ?? ($this->bodies[$action])($this->orderA),
    );
    $this->state = fn (Order $order): OrderStatus => $order->fresh()->status;
    // Suppléant de l'Est sur une période (« AAAA-MM-JJ »).
    $this->deputy = fn (string $from, string $to) => tap(userWithRole(Role::Regional), fn (User $user) => RegionAssignment::factory()
        ->deputy($from, $to)
        ->create(['user_id' => $user->id, 'region_id' => $this->est->id]));
});

describe('rights', function () {
    it('lets the regional of the region validate and reject', function () {
        $this->actingAs($this->regional);

        ($this->call)('validate')->assertOk()->assertJsonPath('status', 'VALIDATED')->assertJsonPath('decided_by.id', $this->regional->id);
        $second = ($this->send)($this->accountA, '2026-10-08');

        ($this->call)('reject', $second->public_id, ['version' => 1, 'reason_code' => 'DOUBLON'])
            ->assertOk()->assertJsonPath('status', 'REJECTED')->assertJsonPath('decided_by.id', $this->regional->id);
    });

    it('refuses with 403 the ADV, the Admin, a client account and an account without role, with no effect', function (string $role, string $action) {
        $this->actingAs(($this->users)($role));

        expectProblem(($this->call)($action), 403, 'FORBIDDEN');
        expect(($this->state)($this->orderA))->toBe(OrderStatus::Submitted)
            ->and($this->orderA->fresh()->version)->toBe(1);
        Event::assertNotDispatched(OrderValidated::class);
        Event::assertNotDispatched(OrderRejected::class);
    })->with(['adv', 'admin', 'client', 'none'])->with(['validate', 'reject']);

    it('requires an authenticated session', function (string $action) {
        expectProblem(($this->call)($action), 401, 'UNAUTHENTICATED');
    })->with(['validate', 'reject']);

    it('follows the order.validate permission of the regional role, not only the role', function (string $action) {
        SpatieRole::findByName(Role::Regional->value)->revokePermissionTo(Permission::OrderValidate->value);
        $this->actingAs($this->regional);

        expectProblem(($this->call)($action), 403, 'FORBIDDEN');
        expect(($this->state)($this->orderA))->toBe(OrderStatus::Submitted);
    })->with(['validate', 'reject']);

    it('does not take order.view for order.validate: the regional can read without deciding, and the other way round', function () {
        SpatieRole::findByName(Role::Regional->value)->revokePermissionTo(Permission::OrderValidate->value);
        $this->actingAs($this->regional);

        $this->getJson('/api/v1/regional/orders/'.$this->orderA->public_id)->assertOk();
        expectProblem(($this->call)('validate'), 403, 'FORBIDDEN');

        // Après une requête, le garde courant n'est plus celui des rôles : recherche par nom seul.
        SpatieRole::query()->where('name', Role::Regional->value)->firstOrFail()->givePermissionTo(Permission::OrderValidate->value)->revokePermissionTo(Permission::OrderView->value);
        expectProblem($this->getJson('/api/v1/regional/orders/'.$this->orderA->public_id), 403, 'FORBIDDEN');
        ($this->call)('validate')->assertOk();
    });

    it('checks the rights before the perimeter and the format (403 before 404 before 422)', function (string $role, string $action) {
        $this->actingAs(($this->users)($role));

        expectProblem(($this->call)($action, $this->orderE->public_id, []), 403, 'FORBIDDEN');
        expectProblem(($this->call)($action, 'not-an-ulid', []), 403, 'FORBIDDEN');
        expectProblem(($this->call)($action, strtoupper((string) Str::ulid()), ['version' => 'x']), 403, 'FORBIDDEN');
    })->with(['adv', 'admin', 'client'])->with(['validate', 'reject']);

    it('answers 404 for an order out of the perimeter before the format of the body (404 before 422)', function (string $action) {
        $this->actingAs($this->regional);

        expectProblem(($this->call)($action, $this->orderE->public_id, []), 404, 'NOT_FOUND');
        expectProblem(($this->call)($action, $this->orderE->public_id, ['version' => 'x', 'lines' => 'x', 'reason_code' => 12]), 404, 'NOT_FOUND');
        // Sa propre commande : le format est contrôlé ensuite.
        expectProblem(($this->call)($action, $this->orderA->public_id, []), 422, 'VALIDATION_FAILED');
        expect(($this->state)($this->orderE))->toBe(OrderStatus::Submitted);
    })->with(['validate', 'reject']);

    it('answers 404 for a malformed order id without querying the orders', function (string $id, string $action) {
        $this->actingAs($this->regional);
        $this->getJson('/api/v1/regional/orders/'.$this->orderA->public_id)->assertOk(); // Réchauffe les caches (permissions, paramètres).

        DB::flushQueryLog();
        DB::enableQueryLog();
        expectProblem(($this->call)($action, $id, ($this->bodies[$action])($this->orderA)), 404, 'NOT_FOUND');
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        expect($queries)->not->toBeEmpty()
            ->and($queries->filter(fn (string $query) => str_contains($query, '"orders"'))->all())->toBe([]);
    })->with([
        'pas un ULID' => ['not-an-ulid'],
        '25 caractères' => ['01JA2Z6K8QW3X9M4T7RBN5CDE'],
        'lettre exclue (U)' => ['01JA2Z6K8QW3X9M4T7RBN5CDEU'],
        'numéro de commande' => ['RMK-261008-00001'],
    ])->with(['validate', 'reject']);

    it('answers 404 for an unknown order, and finds an order whatever the case of its id (I-56)', function () {
        $this->actingAs($this->regional);

        expectProblem(($this->call)('validate', strtoupper((string) Str::ulid())), 404, 'NOT_FOUND');
        ($this->call)('validate', strtolower($this->orderA->public_id))->assertOk()->assertJsonPath('id', $this->orderA->public_id);
    });

    it('does not allow another method, and does not expose a route to delete a decision', function () {
        $this->actingAs($this->regional);

        $this->getJson('/api/v1/regional/orders/'.$this->orderA->public_id.'/validate')->assertStatus(405);
        $this->putJson('/api/v1/regional/orders/'.$this->orderA->public_id.'/reject', [])->assertStatus(405);
        expect(($this->state)($this->orderA))->toBe(OrderStatus::Submitted);
    });
});

describe('perimeter', function () {
    it('keeps the orders of another region out of reach, with no effect', function (string $action) {
        $this->actingAs($this->regional);
        expectProblem(($this->call)($action, $this->orderE->public_id, ($this->bodies[$action])($this->orderE)), 404, 'NOT_FOUND');
        expect(($this->state)($this->orderE))->toBe(OrderStatus::Submitted);

        $this->actingAs($this->regionalEst);
        expectProblem(($this->call)($action, $this->orderA->public_id, ($this->bodies[$action])($this->orderA)), 404, 'NOT_FOUND');
        expect(($this->state)($this->orderA))->toBe(OrderStatus::Submitted);

        // Chacun décide de la commande de sa région.
        ($this->call)($action, $this->orderE->public_id, ($this->bodies[$action])($this->orderE))->assertOk();
        expect(($this->state)($this->orderE))->not->toBe(OrderStatus::Submitted);
    })->with(['validate', 'reject']);

    it('lets a deputy decide during his period only, and never after a revocation (§3.4), and records the real author (RG-S03)', function () {
        $this->actingAs(($this->deputy)('2026-10-10', '2026-10-15'));
        expectProblem(($this->call)('validate', $this->orderE->public_id, ($this->bodies['validate'])($this->orderE)), 404, 'NOT_FOUND');

        $revoked = ($this->deputy)('2026-10-07', '2026-10-09');
        RegionAssignment::query()->where('user_id', $revoked->id)->update(['revoked_at' => now()]);
        $this->actingAs($revoked);
        expectProblem(($this->call)('reject', $this->orderE->public_id, ['version' => 1, 'reason_code' => 'DOUBLON']), 404, 'NOT_FOUND');
        expect(($this->state)($this->orderE))->toBe(OrderStatus::Submitted);

        $deputy = ($this->deputy)('2026-10-07', '2026-10-09');
        $this->actingAs($deputy);
        ($this->call)('validate', $this->orderE->public_id, ($this->bodies['validate'])($this->orderE))
            ->assertOk()
            ->assertJsonPath('status', 'VALIDATED')
            ->assertJsonPath('decided_by.id', $deputy->id)
            ->assertJsonPath('history.1.by_name', $deputy->name);
        expect($this->orderE->fresh()->decided_by)->toBe($deputy->id);
    });

    it('reads the perimeter on the day of the order, not on the day of the request', function () {
        // Dimanche 11 : répartition envoyée la veille, une commande de l'Est.
        ($this->open)('2026-10-11');
        $sunday = ($this->send)($this->accountE, '2026-10-11');
        // Suppléant de l'Est pour le dimanche seulement : rien aujourd'hui.
        $this->actingAs(($this->deputy)('2026-10-11', '2026-10-11'));

        expectProblem(($this->call)('validate', $this->orderE->public_id, ($this->bodies['validate'])($this->orderE)), 404, 'NOT_FOUND');
        // La journée du dimanche est ouverte : seule la période compte, la décision passe.
        ($this->call)('reject', $sunday->public_id, ['version' => 1, 'reason_code' => 'DOUBLON'])->assertOk()->assertJsonPath('status', 'REJECTED');
    });

    it('keeps an order in the region frozen at its submission when the client changes region (RG-S04)', function () {
        $this->e->forceFill(['region_id' => $this->centre->id])->save();

        $this->actingAs($this->regional);
        expectProblem(($this->call)('validate', $this->orderE->public_id, ($this->bodies['validate'])($this->orderE)), 404, 'NOT_FOUND');
        $this->actingAs($this->regionalEst);
        ($this->call)('validate', $this->orderE->public_id, ($this->bodies['validate'])($this->orderE))->assertOk()->assertJsonPath('region.code', 'EST');
        expect($this->orderE->fresh()->region_id)->toBe($this->est->id);
    });
});
