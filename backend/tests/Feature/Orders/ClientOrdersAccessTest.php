<?php

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Orders\ClientOrders;
use App\Domain\Settings\Settings;
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

// Droits sur les points d'accès du client (contrat du lot 5, §2) : rôle
// Client et permissions (order.view + limit.view, order.view,
// order.create), compte rattaché à un client ; l'ADV, l'Admin et le
// régional reçoivent 403 ; 403 avant 404 avant 422 ; commande d'un autre
// client ou identifiant mal formé → 404.

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:30 à Alger : commande jusqu'à 13:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    Event::fake([
        QuotasPublished::class, QuotaDraftsSaved::class, LimitDraftsSaved::class, DistributionPublished::class, CutoffExtended::class,
        DayClosed::class, OrderSubmitted::class, OrderUpdated::class, OrderCancelled::class, OrdersExpired::class,
    ]);
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->a = Client::factory()->create(['code' => 'C0012', 'region_id' => $this->centre->id]);
    $this->b = Client::factory()->create(['code' => 'C0047', 'region_id' => $this->centre->id]);
    $this->orange = Product::factory()->create(['sku' => 'P0042']);
    openOrderingDay('2026-10-08', $this->centre, [[$this->orange, 500]], [[$this->a, $this->orange, 50], [$this->b, $this->orange, 50]]);
    // Plusieurs commandes par jour : chaque envoi du parcours aboutit.
    app(Settings::class)->update(['orders' => ['max_per_day' => 5]], null);

    $this->accountA = userWithRole(Role::Client, ['client_id' => $this->a->id]);
    $this->accountB = userWithRole(Role::Client, ['client_id' => $this->b->id]);
    $send = fn (User $account) => app(ClientOrders::class)->submit(
        $account, (string) Str::uuid(), BusinessClock::parseDay('2026-10-08'), [['product_id' => $this->orange->id, 'quantity' => 10]], null,
    )['order'];
    $this->orderA = $send($this->accountA);
    $this->orderB = $send($this->accountB);

    $this->users = fn (string $role): User => match ($role) {
        'client' => userWithRole(Role::Client, ['client_id' => $this->a->id]),
        'client sans client' => userWithRole(Role::Client),
        'regional' => tap(userWithRole(Role::Regional, ['client_id' => $this->a->id]), fn (User $user) => RegionAssignment::factory()
            ->create(['user_id' => $user->id, 'region_id' => $this->centre->id])),
        'adv' => userWithRole(Role::Adv),
        'admin' => admin(),
        'none' => User::factory()->create(['client_id' => $this->a->id]),
    };
    $this->lines = [['product_id' => $this->orange->id, 'quantity' => 1]];
    // Une requête valide par point d'accès ; `$id` : commande désignée.
    $this->call = fn (string $endpoint, ?string $id = null, ?array $body = null) => match ($endpoint) {
        'today' => $this->getJson('/api/v1/client/today'),
        'index' => $this->getJson('/api/v1/client/orders'),
        'show' => $this->getJson('/api/v1/client/orders/'.($id ?? $this->orderA->public_id)),
        'store' => $this->postJson('/api/v1/client/orders', $body ?? [
            'client_request_id' => (string) Str::uuid(), 'day' => '2026-10-08', 'comment' => null, 'lines' => $this->lines,
        ]),
        'update' => $this->patchJson('/api/v1/client/orders/'.($id ?? $this->orderA->public_id), $body ?? [
            'version' => $this->orderA->fresh()->version, 'comment' => null, 'lines' => [['product_id' => $this->orange->id, 'quantity' => 11]],
        ]),
        'cancel' => $this->postJson('/api/v1/client/orders/'.($id ?? $this->orderA->public_id).'/cancel', $body ?? [
            'version' => $this->orderA->fresh()->version,
        ]),
    };
    $this->endpoints = ['today' => 200, 'index' => 200, 'show' => 200, 'store' => 201, 'update' => 200, 'cancel' => 200];
    $this->forbid = fn (Permission $permission) => SpatieRole::findByName(Role::Client->value)->revokePermissionTo($permission->value);
});

it('lets a client account use every endpoint on the orders of its client', function () {
    $this->actingAs(($this->users)('client'));

    foreach ($this->endpoints as $endpoint => $status) {
        ($this->call)($endpoint)->assertStatus($status);
    }
});

it('refuses with 403 every other role, a client account without client and an account without role', function (string $role) {
    $this->actingAs(($this->users)($role));

    foreach (array_keys($this->endpoints) as $endpoint) {
        expectProblem(($this->call)($endpoint), 403, 'FORBIDDEN');
    }
})->with(['client sans client', 'regional', 'adv', 'admin', 'none']);

it('requires an authenticated session', function (string $endpoint) {
    expectProblem(($this->call)($endpoint), 401, 'UNAUTHENTICATED');
})->with(['today', 'index', 'show', 'store', 'update', 'cancel']);

it('follows the permissions of the client role, not only the role', function (Permission $permission, array $forbidden) {
    ($this->forbid)($permission);
    $this->actingAs(($this->users)('client'));

    foreach ($this->endpoints as $endpoint => $status) {
        if (in_array($endpoint, $forbidden, true)) {
            expectProblem(($this->call)($endpoint), 403, 'FORBIDDEN');
        } else {
            ($this->call)($endpoint)->assertStatus($status);
        }
    }
})->with([
    'order.view' => [Permission::OrderView, ['today', 'index', 'show']],
    'limit.view' => [Permission::LimitView, ['today']],
    'order.create' => [Permission::OrderCreate, ['store', 'update', 'cancel']],
]);

it('checks the rights before the format of the request (403 before 422)', function (string $role) {
    $this->actingAs(($this->users)($role));

    expectProblem($this->getJson('/api/v1/client/today?day=08/10/2026'), 403, 'FORBIDDEN');
    expectProblem(($this->call)('store', body: ['lines' => 'x']), 403, 'FORBIDDEN');
    expectProblem(($this->call)('update', body: []), 403, 'FORBIDDEN');
    expectProblem(($this->call)('cancel', body: []), 403, 'FORBIDDEN');
    expectProblem(($this->call)('update', 'not-an-ulid', []), 403, 'FORBIDDEN');
})->with(['client sans client', 'regional', 'adv']);

it('answers 404 for the order of another client, before the format of the request', function () {
    $this->actingAs(($this->users)('client'));
    $id = $this->orderB->public_id;

    expectProblem(($this->call)('show', $id), 404, 'NOT_FOUND');
    expectProblem(($this->call)('update', $id), 404, 'NOT_FOUND');
    expectProblem(($this->call)('update', $id, ['version' => 'x']), 404, 'NOT_FOUND');
    expectProblem(($this->call)('cancel', $id), 404, 'NOT_FOUND');
    expectProblem(($this->call)('cancel', $id, []), 404, 'NOT_FOUND');
    ($this->call)('show', $id)->assertJsonPath('detail', 'Commande introuvable.');
    expect($this->orderB->fresh())->status->value->toBe('SUBMITTED')->version->toBe(1);

    // La même commande, pour son propre client : le format est contrôlé ensuite (422).
    expectProblem(($this->call)('update', $this->orderA->public_id, ['version' => 'x']), 422, 'VALIDATION_FAILED');
});

it('answers 404 for a malformed order id without querying the orders', function (string $id) {
    $this->actingAs(($this->users)('client'));
    ($this->call)('today')->assertOk(); // Réchauffe les caches (permissions, paramètres).

    DB::flushQueryLog();
    DB::enableQueryLog();
    expectProblem(($this->call)('show', $id), 404, 'NOT_FOUND');
    expectProblem(($this->call)('update', $id, ['version' => 'x']), 404, 'NOT_FOUND');
    expectProblem(($this->call)('cancel', $id, []), 404, 'NOT_FOUND');
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

it('finds an order whatever the case of its id, for every account of the client (I-56, Q-11)', function () {
    $this->actingAs(($this->users)('client'));

    ($this->call)('show', strtolower($this->orderA->public_id))->assertOk()->assertJsonPath('id', $this->orderA->public_id);
    $this->actingAs($this->accountA);
    ($this->call)('show')->assertOk()->assertJsonPath('number', $this->orderA->number);
});
