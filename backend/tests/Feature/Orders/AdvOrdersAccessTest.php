<?php

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Documents\OrderPdf;
use App\Domain\Orders\ClientOrders;
use App\Domain\Orders\OrderStatus;
use App\Domain\Referentials\AssignmentKind;
use App\Domain\Settings\Settings;
use App\Events\CutoffExtended;
use App\Events\DayClosed;
use App\Events\DistributionPublished;
use App\Events\LimitDraftsSaved;
use App\Events\OrderCancelled;
use App\Events\OrderCancelledByAdv;
use App\Events\OrdersExpired;
use App\Events\OrderShippingUpdated;
use App\Events\OrdersProcessed;
use App\Events\OrderSubmitted;
use App\Events\OrderValidated;
use App\Events\QuotaDraftsSaved;
use App\Events\QuotasPublished;
use App\Models\Client;
use App\Models\Depot;
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

// Droits et périmètre de la file ADV (contrat du lot 6, §2 et §5) :
// lecture pour l'ADV et l'Admin (`order.view`), écritures pour l'ADV seul
// (`order.process`, `order.cancel-validated`) ; le régional, le client et un
// compte sans rôle reçoivent 403. 403 avant 404 avant 422. Périmètre RG-S05
// jugé à la journée de chaque commande ; une commande hors de la file
// (envoyée, rejetée) ou hors périmètre répond 404.

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:30 à Alger.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    Event::fake([
        QuotasPublished::class, QuotaDraftsSaved::class, LimitDraftsSaved::class, DistributionPublished::class, CutoffExtended::class,
        DayClosed::class, OrderSubmitted::class, OrderCancelled::class, OrdersExpired::class,
        OrderValidated::class, OrdersProcessed::class, OrderShippingUpdated::class, OrderCancelledByAdv::class,
    ]);
    $this->depot = Depot::factory()->create(['code' => 'BLIDA', 'name' => 'Dépôt de Blida']);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre', 'depot_id' => $this->depot->id]);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    $this->a = Client::factory()->create(['code' => 'C0012', 'region_id' => $this->centre->id]);
    $this->e = Client::factory()->create(['code' => 'C0300', 'region_id' => $this->est->id]);
    $this->orange = Product::factory()->create(['sku' => 'P0042']);
    openOrderingDay('2026-10-08', $this->centre, [[$this->orange, 500]]);
    openOrderingDay('2026-10-08', $this->est, [[$this->orange, 200]]);
    $this->accounts = [];
    $this->submit = function (Client $client, int $quantity = 10, string $day = '2026-10-08'): Order {
        $this->accounts[$client->id] ??= userWithRole(Role::Client, ['client_id' => $client->id]);

        return app(ClientOrders::class)->submit(
            $this->accounts[$client->id], (string) Str::uuid(), BusinessClock::parseDay($day), [['product_id' => $this->orange->id, 'quantity' => $quantity]], null,
        )['order'];
    };
    $this->orderA = validateOrder(($this->submit)($this->a));
    $this->orderE = validateOrder(($this->submit)($this->e));
    $this->unknown = strtoupper((string) Str::ulid());
    app(Settings::class)->update(['orders' => ['max_per_day' => 3]], null);

    $this->restricted = fn (Region $region, string $from = '2026-10-01'): User => tap(userWithRole(Role::Adv), fn (User $user) => RegionAssignment::factory()->create([
        'region_id' => $region->id, 'user_id' => $user->id, 'kind' => AssignmentKind::Adv, 'starts_on' => $from,
    ]));
    $this->users = fn (string $role): User => match ($role) {
        'adv' => $this->adv ??= userWithRole(Role::Adv),
        'admin' => $this->admin ??= admin(),
        'regional' => $this->regional ??= userWithRole(Role::Regional),
        'client' => $this->accounts[$this->a->id],
        'none' => $this->none ??= User::factory()->create(),
    };
    // Corps valides pour chaque point d'accès, sur la commande donnée.
    $this->call = fn (string $endpoint, ?string $id = null, array $body = []) => match ($endpoint) {
        'index' => $this->getJson('/api/v1/adv/orders'),
        'show' => $this->getJson('/api/v1/adv/orders/'.($id ?? $this->orderA->public_id)),
        'pdf' => $this->getJson('/api/v1/adv/orders/'.($id ?? $this->orderA->public_id).'/pdf'),
        'bulk' => $this->postJson('/api/v1/adv/orders/bulk-status', $body ?: ['status' => 'IN_PREPARATION', 'orders' => [['id' => $id ?? $this->orderA->public_id, 'version' => 2]]]),
        'shipping' => $this->patchJson('/api/v1/adv/orders/'.($id ?? $this->orderA->public_id).'/shipping', $body ?: ['version' => 2, 'delivery_note_ref' => 'BL-1']),
        'cancel' => $this->postJson('/api/v1/adv/orders/'.($id ?? $this->orderA->public_id).'/cancel', $body ?: ['version' => 2, 'reason_code' => 'RUPTURE']),
    };
});

describe('rights', function () {
    it('lets the ADV read the queue and write on it', function () {
        $this->actingAs(($this->users)('adv'));

        ($this->call)('index')->assertOk()->assertJsonCount(2, 'data');
        ($this->call)('show')->assertOk()->assertJsonPath('id', $this->orderA->public_id);
        ($this->call)('bulk')->assertOk()->assertJsonPath('applied.0.status', 'IN_PREPARATION');
        ($this->call)('shipping', body: ['version' => 3, 'delivery_note_ref' => 'BL-1'])->assertOk()->assertJsonPath('delivery_note_ref', 'BL-1');
        ($this->call)('cancel', body: ['version' => 4, 'reason_code' => 'RUPTURE'])->assertOk()->assertJsonPath('status', 'CANCELLED');
    });

    it('lets the Admin read the queue and the detail, without any action', function () {
        $this->actingAs(($this->users)('admin'));

        ($this->call)('index')->assertOk()->assertJsonCount(2, 'data');
        ($this->call)('show', $this->orderE->public_id)->assertOk()->assertJsonPath('id', $this->orderE->public_id);
    });

    it('refuses the Admin every write and the order PDF with 403 (I-87)', function (string $endpoint) {
        $this->actingAs(($this->users)('admin'));

        expectProblem(($this->call)($endpoint), 403, 'FORBIDDEN');
        expect($this->orderA->fresh()->status)->toBe(OrderStatus::Validated)->and($this->orderA->fresh()->version)->toBe(2);
    })->with(['bulk', 'shipping', 'cancel', 'pdf']);

    it('refuses with 403 the regional, a client account and an account without role, on every endpoint', function (string $role, string $endpoint) {
        $this->actingAs(($this->users)($role));

        expectProblem(($this->call)($endpoint), 403, 'FORBIDDEN');
    })->with(['regional', 'client', 'none'])->with(['index', 'show', 'pdf', 'bulk', 'shipping', 'cancel']);

    it('requires an authenticated session', function (string $endpoint) {
        expectProblem(($this->call)($endpoint), 401, 'UNAUTHENTICATED');
    })->with(['index', 'show', 'pdf', 'bulk', 'shipping', 'cancel']);

    it('follows the permissions of the roles, not only the roles', function (Permission $revoked, array $refused, array $allowed) {
        SpatieRole::findByName(Role::Adv->value)->revokePermissionTo($revoked->value);
        $this->actingAs(($this->users)('adv'));

        foreach ($refused as $endpoint) {
            expectProblem(($this->call)($endpoint), 403, 'FORBIDDEN');
        }
        foreach ($allowed as $endpoint) {
            ($this->call)($endpoint)->assertOk();
        }
    })->with([
        'order.view' => [Permission::OrderView, ['index', 'show'], []],
        'order.process' => [Permission::OrderProcess, ['bulk', 'shipping', 'pdf'], ['index', 'show']],
        'order.cancel-validated' => [Permission::OrderCancelValidated, ['cancel'], ['index', 'show', 'bulk']],
    ]);

    it('answers 403 before 404 and 422 on the order endpoints', function (string $role) {
        $this->actingAs(($this->users)($role));

        foreach (['show', 'pdf'] as $endpoint) {
            expectProblem(($this->call)($endpoint, 'not-an-ulid'), 403, 'FORBIDDEN');
            expectProblem(($this->call)($endpoint, $this->unknown), 403, 'FORBIDDEN');
        }
        expectProblem(($this->call)('shipping', $this->unknown, ['version' => 'x']), 403, 'FORBIDDEN');
        expectProblem(($this->call)('cancel', 'not-an-ulid', ['reason_code' => 'BOGUS']), 403, 'FORBIDDEN');
        expectProblem(($this->call)('bulk', body: ['status' => 'DELIVERED', 'orders' => []]), 403, 'FORBIDDEN');
    })->with(['regional', 'client', 'none']);

    it('answers 404 before 422 for an unknown order, as for a malformed id', function (string $endpoint) {
        $this->actingAs(($this->users)('adv'));
        $invalid = ['version' => 'x', 'reason_code' => 'BOGUS'];

        expectProblem(($this->call)($endpoint, 'not-an-ulid', $invalid), 404, 'NOT_FOUND');
        expectProblem(($this->call)($endpoint, $this->unknown, $invalid), 404, 'NOT_FOUND');
    })->with(['shipping', 'cancel']);
});

describe('target and perimeter', function () {
    it('answers 404 for a malformed order id without querying the orders', function (string $id) {
        $this->actingAs(($this->users)('adv'));
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

    it('finds an order whatever the case of its id (I-56)', function () {
        $this->actingAs(($this->users)('adv'));

        ($this->call)('show', strtolower($this->orderA->public_id))->assertOk()->assertJsonPath('id', $this->orderA->public_id);
    });

    it('answers 404 for an order outside the queue: sent, rejected, expired, cancelled by the client', function (string $state) {
        $order = ($this->submit)($this->a);
        $order = match ($state) {
            'submitted' => $order,
            'rejected' => tap($order, fn (Order $o) => $o->forceFill(['status' => OrderStatus::Rejected, 'decided_at' => now()])->save()),
            'expired' => tap($order, fn (Order $o) => $o->forceFill(['status' => OrderStatus::Expired])->save()),
            'cancelled by the client' => tap($order, fn (Order $o) => $o->forceFill(['status' => OrderStatus::Cancelled])->save()),
        };

        foreach (['adv', 'admin'] as $role) {
            $this->actingAs(($this->users)($role));
            expectProblem(($this->call)('show', $order->public_id), 404, 'NOT_FOUND');
        }
        $this->actingAs(($this->users)('adv'));
        foreach (['shipping', 'cancel', 'pdf'] as $endpoint) {
            expectProblem(($this->call)($endpoint, $order->public_id), 404, 'NOT_FOUND');
        }
    })->with(['submitted', 'rejected', 'expired', 'cancelled by the client']);

    it('hands the order to the document once the rights and the perimeter are resolved, and returns its response as is (I-86)', function () {
        $expected = $this->orderA->public_id;
        $this->mock(OrderPdf::class, fn ($mock) => $mock->shouldReceive('response')
            ->once()
            ->withArgs(fn (Order $order) => $order->public_id === $expected && $order->status === OrderStatus::Validated)
            ->andReturn(response('%PDF-1.4 test', 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'no-store'])));
        $this->actingAs(($this->users)('adv'));

        ($this->call)('pdf', strtolower($expected))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private');
    })->skip(fn () => class_exists(OrderPdf::class) && (new ReflectionClass(OrderPdf::class))->isFinal(), 'OrderPdf est final : son contenu et sa délégation sont testés avec lui.');

    it('never hands an order out of the perimeter or the queue to the document', function () {
        $this->mock(OrderPdf::class, fn ($mock) => $mock->shouldNotReceive('response'));
        $this->actingAs(($this->restricted)($this->est));

        expectProblem(($this->call)('pdf', $this->orderA->public_id), 404, 'NOT_FOUND');
        expectProblem(($this->call)('pdf', ($this->submit)($this->e)->public_id), 404, 'NOT_FOUND');
        $this->actingAs(admin());
        expectProblem(($this->call)('pdf', $this->orderE->public_id), 403, 'FORBIDDEN');
    })->skip(fn () => class_exists(OrderPdf::class) && (new ReflectionClass(OrderPdf::class))->isFinal(), 'OrderPdf est final : son contenu et sa délégation sont testés avec lui.');

    it('keeps an order cancelled after its validation in the queue (I-90)', function () {
        $this->actingAs(($this->users)('adv'));
        ($this->call)('cancel', body: ['version' => 2, 'reason_code' => 'RUPTURE'])->assertOk();

        ($this->call)('show')->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->getJson('/api/v1/adv/orders?filter[status]=CANCELLED')->assertOk()->assertJsonPath('data.*.id', [$this->orderA->public_id]);
    });

    it('limits a restricted ADV to the regions of its assignments, at the day of each order (RG-S05)', function () {
        $restricted = ($this->restricted)($this->est);
        $this->actingAs($restricted);

        ($this->call)('index')->assertOk()->assertJsonPath('data.*.id', [$this->orderE->public_id]);
        ($this->call)('show', $this->orderE->public_id)->assertOk();
        foreach (['show', 'pdf'] as $endpoint) {
            expectProblem(($this->call)($endpoint, $this->orderA->public_id), 404, 'NOT_FOUND');
        }
        expectProblem(($this->call)('shipping', $this->orderA->public_id), 404, 'NOT_FOUND');
        expectProblem(($this->call)('cancel', $this->orderA->public_id), 404, 'NOT_FOUND');
        expect($this->orderA->fresh()->status)->toBe(OrderStatus::Validated);
    });

    it('judges the perimeter at the day of the order, not today (I-90)', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:30', 'Africa/Algiers'));
        openOrderingDay('2026-10-07', $this->centre, [[$this->orange, 500]]);
        $yesterday = validateOrder(($this->submit)($this->a, 10, '2026-10-07'));
        $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));

        // Un ADV affecté au Centre à partir du lendemain : la commande du 8 reste hors périmètre.
        $this->actingAs(($this->restricted)($this->centre, '2026-10-09'));
        expectProblem(($this->call)('show', $this->orderA->public_id), 404, 'NOT_FOUND');
        ($this->call)('index')->assertOk()->assertJsonCount(0, 'data');

        // Un ADV dont l'affectation au Centre a pris fin la veille : il lit encore la commande de la veille.
        $past = ($this->restricted)($this->centre, '2026-10-01');
        RegionAssignment::query()->where('user_id', $past->id)->update(['ends_on' => '2026-10-07']);
        forgetGuards();
        $this->actingAs($past);

        ($this->call)('show', $yesterday->public_id)->assertOk();
        ($this->call)('index')->assertOk()->assertJsonPath('data.*.id', [$yesterday->public_id]);
        expectProblem(($this->call)('show', $this->orderA->public_id), 404, 'NOT_FOUND');
        $this->getJson('/api/v1/adv/orders?filter[region]='.$this->centre->id)->assertOk()->assertJsonPath('data.*.id', [$yesterday->public_id]);
        $this->getJson('/api/v1/adv/orders?filter[day]=2026-10-07&filter[region]='.$this->centre->id)->assertOk()->assertJsonPath('data.*.id', [$yesterday->public_id]);
        // Jugée à la journée filtrée : le 8, le Centre n'est plus dans son périmètre.
        expectProblem($this->getJson('/api/v1/adv/orders?filter[day]=2026-10-08&filter[region]='.$this->centre->id), 404, 'NOT_FOUND');
    });

    it('lets an ADV never assigned and the Admin see every region', function (string $role) {
        $this->actingAs(($this->users)($role));

        ($this->call)('index')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/adv/orders?filter[region]='.$this->est->id.'&filter[depot]=none')->assertOk()->assertJsonCount(1, 'data');
    })->with(['adv', 'admin']);

    it('answers 404 for a region or a depot outside the perimeter, before the format of the other parameters', function () {
        $this->actingAs(($this->restricted)($this->est));

        foreach (['?filter[region]='.$this->centre->id.'&filter[status]=DRAFT', '?filter[region]='.$this->centre->id.'&sort=bogus', '?filter[region]=999999'] as $query) {
            expectProblem($this->getJson('/api/v1/adv/orders'.$query), 404, 'NOT_FOUND');
        }
        expectProblem($this->getJson('/api/v1/adv/orders?filter[depot]='.$this->depot->id.'&filter[status]=DRAFT'), 404, 'NOT_FOUND');
        expectProblem($this->getJson('/api/v1/adv/orders?filter[depot]=999999'), 404, 'NOT_FOUND');
        // Sa propre région ou « sans dépôt » : le format est contrôlé ensuite.
        expectProblem($this->getJson('/api/v1/adv/orders?filter[region]='.$this->est->id.'&filter[status]=DRAFT'), 422, 'VALIDATION_FAILED');
        $this->getJson('/api/v1/adv/orders?filter[depot]=none')->assertOk()->assertJsonCount(1, 'data');
    });

    it('answers 404 for an unknown region or depot even to an unrestricted ADV and to the Admin', function (string $role) {
        $this->actingAs(($this->users)($role));

        expectProblem($this->getJson('/api/v1/adv/orders?filter[region]=999999'), 404, 'NOT_FOUND');
        expectProblem($this->getJson('/api/v1/adv/orders?filter[depot]=999999'), 404, 'NOT_FOUND');
        $this->getJson('/api/v1/adv/orders?filter[depot]='.$this->depot->id)->assertOk()->assertJsonPath('data.*.id', [$this->orderA->public_id]);
    })->with(['adv', 'admin']);

    it('refuses to a restricted ADV the orders it cannot see in a bulk change, like unknown ones, without locking them', function () {
        $this->actingAs(($this->restricted)($this->est));

        $response = $this->postJson('/api/v1/adv/orders/bulk-status', ['status' => 'IN_PREPARATION', 'orders' => [
            ['id' => $this->orderA->public_id, 'version' => 2],
            ['id' => $this->orderE->public_id, 'version' => 2],
            ['id' => $this->unknown, 'version' => 1],
        ]])->assertOk();

        expect($response->json('applied.*.id'))->toBe([$this->orderE->public_id])
            ->and($response->json('refused'))->toBe([
                ['id' => $this->orderA->public_id, 'number' => null, 'code' => 'NOT_FOUND', 'detail' => 'Commande introuvable.', 'version' => null],
                ['id' => $this->unknown, 'number' => null, 'code' => 'NOT_FOUND', 'detail' => 'Commande introuvable.', 'version' => null],
            ])
            ->and($this->orderA->fresh()->status)->toBe(OrderStatus::Validated);
    });
});
