<?php

use App\Domain\Access\Role;
use App\Domain\Limits\LimitBook;
use App\Domain\Orders\ClientOrders;
use App\Domain\Orders\OrderStatus;
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
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionQuota;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

// Commandes reçues : GET /regional/orders et GET /regional/orders/{id}
// (EF-REG-04 ; contrat du lot 5, §4) — commandes de la journée dans les
// régions du périmètre, par heure d'envoi croissante, onglets par statut
// et leurs compteurs, pagination ; détail avec le client, la région,
// l'historique nommé et le contexte de quotas publié de chaque ligne.

beforeEach(function () {
    Event::fake([
        QuotasPublished::class, QuotaDraftsSaved::class, LimitDraftsSaved::class, DistributionPublished::class, CutoffExtended::class,
        DayClosed::class, OrderSubmitted::class, OrderUpdated::class, OrderCancelled::class, OrdersExpired::class,
    ]);
    $at = fn (string $moment) => $this->travelTo(CarbonImmutable::parse($moment, 'Africa/Algiers'));
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    $this->regional = userWithRole(Role::Regional, ['name' => 'Karim Régional']);
    RegionAssignment::factory()->create(['user_id' => $this->regional->id, 'region_id' => $this->centre->id]);

    $this->a = Client::factory()->create([
        'code' => 'C0012', 'name' => 'Superette El Baraka', 'region_id' => $this->centre->id, 'phone' => '0550123456', 'phone2' => null,
    ]);
    $this->b = Client::factory()->create(['code' => 'C0047', 'name' => 'Alimentation Nour', 'region_id' => $this->centre->id, 'phone' => null, 'phone2' => '0660987654']);
    $this->c = Client::factory()->create(['code' => 'C0300', 'region_id' => $this->est->id]);
    $this->accountA = userWithRole(Role::Client, ['client_id' => $this->a->id, 'name' => 'Gérant C0012']);
    $this->secondA = userWithRole(Role::Client, ['client_id' => $this->a->id, 'name' => 'Vendeur C0012']);
    $this->accountB = userWithRole(Role::Client, ['client_id' => $this->b->id, 'name' => 'Gérant C0047']);
    $this->accountC = userWithRole(Role::Client, ['client_id' => $this->c->id]);
    $this->orange = Product::factory()->create(['sku' => 'P0042', 'name' => 'Jus orange 1 L', 'short_name' => 'Orange 1 L', 'sales_unit_label' => 'Fardeau 6 × 1 L', 'vat_rate' => '19.00']);
    $this->pomme = Product::factory()->create(['sku' => 'P0057', 'name' => 'Jus pomme 1 L', 'short_name' => 'Pomme 1 L', 'sales_unit_label' => 'Fardeau 6 × 1 L', 'vat_rate' => '19.00']);
    ProductPrice::factory()->create(['product_id' => $this->orange->id, 'price_ht' => '900.00', 'valid_from' => '2026-01-01']);
    ProductPrice::factory()->create(['product_id' => $this->pomme->id, 'price_ht' => '850.50', 'valid_from' => '2026-01-01']);
    app(Settings::class)->update(['orders' => ['max_per_day' => 3]], null);
    $this->submit = fn (User $by, array $lines) => app(ClientOrders::class)->submit(
        $by,
        (string) Str::uuid(),
        BusinessClock::today(),
        array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
        null,
    )['order'];

    // Mercredi 7 : une commande du Centre, restée envoyée.
    $at('2026-10-07 09:00');
    openOrderingDay('2026-10-07', $this->centre, [[$this->orange, 500]]);
    $this->yesterday = ($this->submit)($this->accountB, [[$this->orange, 1]]);

    // Jeudi 8 : orange borne 50 pour A ; pomme sans borne.
    $at('2026-10-08 08:00');
    $this->regionDay = openOrderingDay('2026-10-08', $this->centre, [[$this->orange, 500], [$this->pomme, 300]], [[$this->a, $this->orange, 50]]);
    openOrderingDay('2026-10-08', $this->est, [[$this->orange, 200]]);
    $at('2026-10-08 08:40');
    $this->first = ($this->submit)($this->accountB, [[$this->pomme, 4]]);
    $at('2026-10-08 08:50');
    $this->second = ($this->submit)($this->accountA, [[$this->orange, 40], [$this->pomme, 2]]);
    $at('2026-10-08 09:00');
    $this->cancelled = ($this->submit)($this->secondA, [[$this->pomme, 3]]);
    ($this->submit)($this->accountC, [[$this->orange, 10]]);
    $at('2026-10-08 09:05');
    app(ClientOrders::class)->cancel($this->secondA, $this->cancelled->public_id, 1);
    $at('2026-10-08 09:10');
    $this->second = app(ClientOrders::class)->modify($this->secondA, $this->second->public_id, 1, [
        ['product_id' => $this->orange->id, 'quantity' => 45], ['product_id' => $this->pomme->id, 'quantity' => 2],
    ], 'Livraison avant 10 h');

    // Décisions simulées (lot 6) : une commande validée avec ajustement (V(c,p) et V(r,p) à 15), une rejetée, une en préparation, une expirée.
    $at('2026-10-08 09:20');
    $this->validated = Order::factory()->validated()->create([
        'number' => 'RMK-261008-00090', 'business_day_id' => $this->regionDay->business_day_id, 'region_id' => $this->centre->id, 'client_id' => $this->a->id,
        'submitted_at' => CarbonImmutable::parse('2026-10-08 08:30', 'Africa/Algiers')->utc(), 'submitted_by' => $this->accountA->id,
        'is_adjusted' => true, 'decided_by' => $this->regional->id, 'decision_comment' => 'Quota limité',
        'total_ht' => '13500.00', 'total_vat' => '2565.00', 'total_ttc' => '16065.00', 'version' => 2,
    ]);
    OrderLine::factory()->for($this->validated)->create([
        'product_id' => $this->orange->id, 'requested_qty' => 20, 'validated_qty' => 15, 'unit_price_ht' => '900.00', 'vat_rate' => '19.00',
        'adjustment_reason_code' => 'QUOTA_INSUFFISANT',
    ]);
    OrderStatusHistory::create([
        'order_id' => $this->validated->id, 'from_status' => null, 'to_status' => OrderStatus::Submitted, 'version' => 1,
        'user_id' => $this->accountA->id, 'created_at' => CarbonImmutable::parse('2026-10-08 08:30', 'Africa/Algiers')->utc(),
    ]);
    OrderStatusHistory::create([
        'order_id' => $this->validated->id, 'from_status' => OrderStatus::Submitted, 'to_status' => OrderStatus::Validated, 'version' => 2,
        'user_id' => $this->regional->id, 'comment' => 'Quota limité', 'created_at' => now(),
    ]);
    RegionQuota::query()->where('region_day_id', $this->regionDay->id)->where('product_id', $this->orange->id)->update(['validated_qty' => 15]);
    $factory = fn (string $state, string $time) => Order::factory()->{$state}()->create([
        'business_day_id' => $this->regionDay->business_day_id, 'client_id' => $this->b->id,
        'submitted_at' => CarbonImmutable::parse("2026-10-08 {$time}", 'Africa/Algiers')->utc(),
    ]);
    $this->rejected = $factory('rejected', '08:20');
    $this->preparing = Order::factory()->status(OrderStatus::InPreparation)->create([
        'business_day_id' => $this->regionDay->business_day_id, 'client_id' => $this->b->id,
        'submitted_at' => CarbonImmutable::parse('2026-10-08 08:10', 'Africa/Algiers')->utc(),
    ]);
    $this->expired = $factory('expired', '08:15');

    // Bornes en brouillon, jamais lues : A pomme 10 (pas de borne publiée), A orange 60 (borne publiée 50).
    app(LimitBook::class)->saveDrafts($this->regionDay, [
        ['client_id' => $this->a->id, 'product_id' => $this->pomme->id, 'limit' => 10],
        ['client_id' => $this->a->id, 'product_id' => $this->orange->id, 'limit' => 60],
    ], $this->regional);

    $at('2026-10-08 09:30');
    $this->index = fn (string $query = '') => $this->actingAs($this->regional)->getJson('/api/v1/regional/orders'.$query);
    $this->show = fn (Order $order) => $this->actingAs($this->regional)->getJson("/api/v1/regional/orders/{$order->public_id}");
    $this->summary = function (Order $order): array {
        $order->refresh();

        return [
            'id' => $order->public_id, 'number' => $order->number, 'day' => $order->businessDay->dayKey(),
            'status' => $order->status->value, 'status_label' => $order->status->label(), 'version' => $order->version,
            'submitted_at' => $order->submitted_at->toIso8601String(), 'updated_at' => $order->updated_at->toIso8601String(),
            'client' => ['id' => $order->client->id, 'code' => $order->client->code, 'name' => $order->client->name, 'status' => $order->client->status->value],
            'region' => ['id' => $order->region->id, 'code' => $order->region->code, 'name' => $order->region->name],
            'lines_count' => $order->lines()->count(),
            // Montant tel que le relit json_decode : 9000 pour 9 000,00.
            'total_ht' => json_decode(json_encode((float) $order->total_ht)),
            'is_adjusted' => $order->is_adjusted,
        ];
    };
    $this->counts = ['SUBMITTED' => 2, 'VALIDATED' => 2, 'REJECTED' => 1, 'CLOSED' => 2];
});

describe('GET /regional/orders', function () {
    it('lists the orders to validate of the day and region, first submitted first, with the counts of every tab (RG-V01)', function () {
        $response = ($this->index)()->assertOk();

        expect($response->json('data'))->toBe([($this->summary)($this->first), ($this->summary)($this->second)])
            ->and($response->json('meta'))->toMatchArray([
                'current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 2, 'from' => 1, 'to' => 2, 'counts' => $this->counts,
            ]);
    });

    it('gives the exact shape of a listed order', function () {
        ($this->index)()->assertOk()->assertJsonPath('data.1', [
            'id' => $this->second->public_id,
            'number' => 'RMK-261008-00002',
            'day' => '2026-10-08',
            'status' => 'SUBMITTED',
            'status_label' => 'Envoyée',
            'version' => 2,
            'submitted_at' => '2026-10-08T07:50:00+00:00',
            'updated_at' => '2026-10-08T08:10:00+00:00',
            'client' => ['id' => $this->a->id, 'code' => 'C0012', 'name' => 'Superette El Baraka', 'status' => 'ACTIVE'],
            'region' => ['id' => $this->centre->id, 'code' => 'CENTRE', 'name' => 'Centre'],
            'lines_count' => 2,
            'total_ht' => 42201,
            'is_adjusted' => false,
        ]);
    });

    it('filters a tab by a list of statuses, whatever the case, without changing the counts', function (string $query, array $orders) {
        $response = ($this->index)($query)->assertOk();

        expect($response->json('data.*.id'))->toBe(array_map(fn (string $name) => $this->{$name}->public_id, $orders))
            ->and($response->json('meta.counts'))->toBe($this->counts);
    })->with([
        'À valider' => ['?filter[status]=SUBMITTED', ['first', 'second']],
        'Validées' => ['?filter[status]=VALIDATED,IN_PREPARATION,PREPARED,SHIPPED,DELIVERED', ['preparing', 'validated']],
        'Rejetées' => ['?filter[status]=rejected', ['rejected']],
        'Annulées et expirées' => ['?filter[status]=cancelled, EXPIRED', ['expired', 'cancelled']],
        'liste en tableau' => ['?filter[status][]=REJECTED&filter[status][]=SUBMITTED', ['rejected', 'first', 'second']],
        'statut sans commande' => ['?filter[status]=SHIPPED', []],
    ]);

    it('reads another day, and nothing for a day not opened', function () {
        ($this->index)('?day=2026-10-07')->assertOk()
            ->assertJsonPath('data.*.id', [$this->yesterday->public_id])
            ->assertJsonPath('meta.counts', ['SUBMITTED' => 1, 'VALIDATED' => 0, 'REJECTED' => 0, 'CLOSED' => 0]);
        ($this->index)('?day=2026-10-20')->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.counts', ['SUBMITTED' => 0, 'VALIDATED' => 0, 'REJECTED' => 0, 'CLOSED' => 0]);
    });

    it('paginates by 50, 100 at most', function () {
        ($this->index)('?filter[status]=SUBMITTED,VALIDATED,IN_PREPARATION&per_page=2')->assertOk()
            ->assertJsonPath('data.*.id', [$this->preparing->public_id, $this->validated->public_id])
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.counts', $this->counts);
        ($this->index)('?filter[status]=SUBMITTED,VALIDATED,IN_PREPARATION&per_page=2&page=2')->assertOk()
            ->assertJsonPath('data.*.id', [$this->first->public_id, $this->second->public_id])
            ->assertJsonPath('meta.current_page', 2);
        ($this->index)('?per_page=500')->assertOk()->assertJsonPath('meta.per_page', 100);
    });

    it('lists an order sent through the client API at once', function () {
        $order = $this->actingAs($this->accountB)->postJson('/api/v1/client/orders', [
            'client_request_id' => (string) Str::uuid(), 'day' => '2026-10-08', 'comment' => null,
            'lines' => [['product_id' => $this->orange->id, 'quantity' => 5]],
        ])->assertCreated()->json('id');

        ($this->index)()->assertOk()
            ->assertJsonPath('data.*.id', [$this->first->public_id, $this->second->public_id, $order])
            ->assertJsonPath('data.2.number', 'RMK-261008-00005')
            ->assertJsonPath('meta.counts.SUBMITTED', 3);
    });

    it('answers 422 to an invalid parameter, an unknown filter or a sort', function (string $query, string $field) {
        $response = ($this->index)($query);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => [$field]]);
    })->with([
        'journée mal formée' => ['?day=08/10/2026', 'day'],
        'journée inexistante' => ['?day=2026-02-30', 'day'],
        'journée en tableau' => ['?day[]=2026-10-08', 'day'],
        'statut inconnu' => ['?filter[status]=SUBMITTED,DRAFT', 'filter.status'],
        'statut vide dans la liste' => ['?filter[status]=SUBMITTED,', 'filter.status'],
        'statut imbriqué' => ['?filter[status][0][]=SUBMITTED', 'filter.status'],
        'région mal formée' => ['?filter[region]=centre', 'filter.region'],
        'plusieurs régions' => ['?filter[region]=1,2', 'filter.region'],
        'région en tableau' => ['?filter[region][]=1', 'filter.region'],
        'filtre inconnu' => ['?filter[client_id]=1', 'filter'],
        'filtre sans nom' => ['?filter=SUBMITTED', 'filter'],
        'tri' => ['?sort=-submitted_at', 'sort'],
    ]);

    it('reads a bounded number of queries, whatever the number of orders', function () {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            ($this->index)('?filter[status]=SUBMITTED,REJECTED')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $queries(); // Réchauffe les caches (permissions, paramètres).
        $few = $queries();
        foreach (Client::factory()->count(5)->create(['region_id' => $this->centre->id]) as $client) {
            $order = Order::factory()->create(['business_day_id' => $this->regionDay->business_day_id, 'client_id' => $client->id]);
            OrderLine::factory()->for($order)->count(2)->create(['product_id' => fn () => Product::factory()]);
            Order::factory()->rejected()->create(['business_day_id' => $this->regionDay->business_day_id, 'client_id' => $client->id]);
        }

        expect($queries())->toBe($few)
            ->and(($this->index)('?filter[status]=SUBMITTED,REJECTED')->json('meta.total'))->toBe(13);
    });
});

describe('GET /regional/orders/{id}', function () {
    it('shows a submitted order: client and phones, region, lines with their published context, history with every author', function () {
        ($this->show)($this->second)->assertOk()->assertExactJson([
            'id' => $this->second->public_id,
            'number' => 'RMK-261008-00002',
            'day' => '2026-10-08',
            'status' => 'SUBMITTED',
            'status_label' => 'Envoyée',
            'version' => 2,
            'submitted_at' => '2026-10-08T07:50:00+00:00',
            'total_ht' => 42201.0,
            'total_ttc' => 50219.19,
            'lines_count' => 2,
            'is_adjusted' => false,
            'submitted_by' => ['id' => $this->accountA->id, 'name' => 'Gérant C0012'],
            'comment' => 'Livraison avant 10 h',
            'total_vat' => 8018.19,
            'decided_at' => null,
            'decision_reason_code' => null,
            'decision_comment' => null,
            'client' => ['id' => $this->a->id, 'code' => 'C0012', 'name' => 'Superette El Baraka', 'status' => 'ACTIVE', 'phone' => '0550123456', 'phone2' => null],
            'region' => ['id' => $this->centre->id, 'code' => 'CENTRE', 'name' => 'Centre'],
            'lines' => [
                [
                    'product_id' => $this->orange->id, 'sku' => 'P0042', 'name' => 'Jus orange 1 L', 'short_name' => 'Orange 1 L',
                    'sales_unit_label' => 'Fardeau 6 × 1 L', 'photo' => null, 'requested_qty' => 45, 'validated_qty' => null, 'shipped_qty' => null,
                    'unit_price_ht' => 900.0, 'vat_rate' => 19.0, 'amount_ht' => 40500.0, 'adjustment_reason_code' => null,
                    // Borne publiée (le brouillon à 60 est ignoré), V(c,p) de la commande validée, Q, V(r,p) et R.
                    'context' => ['limit' => 50, 'client_validated' => 15, 'region_quota' => 500, 'region_validated' => 15, 'region_remaining' => 485],
                ],
                [
                    'product_id' => $this->pomme->id, 'sku' => 'P0057', 'name' => 'Jus pomme 1 L', 'short_name' => 'Pomme 1 L',
                    'sales_unit_label' => 'Fardeau 6 × 1 L', 'photo' => null, 'requested_qty' => 2, 'validated_qty' => null, 'shipped_qty' => null,
                    'unit_price_ht' => 850.5, 'vat_rate' => 19.0, 'amount_ht' => 1701.0, 'adjustment_reason_code' => null,
                    // Pas de borne publiée : le brouillon à 10 n'en est pas une.
                    'context' => ['limit' => null, 'client_validated' => 0, 'region_quota' => 300, 'region_validated' => 0, 'region_remaining' => 300],
                ],
            ],
            'history' => [
                ['from' => null, 'to' => 'SUBMITTED', 'version' => 1, 'at' => '2026-10-08T07:50:00+00:00', 'actor' => 'CLIENT', 'by_name' => 'Gérant C0012', 'comment' => null],
                ['from' => 'SUBMITTED', 'to' => 'SUBMITTED', 'version' => 2, 'at' => '2026-10-08T08:10:00+00:00', 'actor' => 'CLIENT', 'by_name' => 'Vendeur C0012', 'comment' => null],
            ],
            'can_validate' => false,
        ]);
    });

    it('shows a decided order with the regional named in its history, and no action of the client', function () {
        $response = ($this->show)($this->validated)->assertOk()
            ->assertJsonPath('status', 'VALIDATED')
            ->assertJsonPath('is_adjusted', true)
            ->assertJsonPath('decided_at', '2026-10-08T08:20:00+00:00')
            ->assertJsonPath('decision_comment', 'Quota limité')
            ->assertJsonPath('lines.0.validated_qty', 15)
            ->assertJsonPath('lines.0.amount_ht', 13500)
            ->assertJsonPath('lines.0.adjustment_reason_code', 'QUOTA_INSUFFISANT')
            ->assertJsonPath('lines.0.context', ['limit' => 50, 'client_validated' => 15, 'region_quota' => 500, 'region_validated' => 15, 'region_remaining' => 485])
            ->assertJsonPath('history.1', [
                'from' => 'SUBMITTED', 'to' => 'VALIDATED', 'version' => 2, 'at' => '2026-10-08T08:20:00+00:00',
                'actor' => 'REGIONAL', 'by_name' => 'Karim Régional', 'comment' => 'Quota limité',
            ])
            ->assertJsonPath('can_validate', false);

        expect($response->json())->not->toHaveKeys(['can_modify', 'can_cancel', 'modifiable_until']);
    });

    it('shows an order of another day of the perimeter, and a blocked client', function () {
        $this->b->forceFill(['status' => 'BLOCKED'])->save();

        ($this->show)($this->yesterday)->assertOk()
            ->assertJsonPath('day', '2026-10-07')
            ->assertJsonPath('client', ['id' => $this->b->id, 'code' => 'C0047', 'name' => 'Alimentation Nour', 'status' => 'BLOCKED', 'phone' => null, 'phone2' => '0660987654'])
            ->assertJsonPath('lines.0.context', ['limit' => null, 'client_validated' => 0, 'region_quota' => 500, 'region_validated' => 0, 'region_remaining' => 500]);
    });

    it('reads a bounded number of queries, whatever the number of lines', function () {
        $queries = function (Order $order): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            ($this->show)($order)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $queries($this->first); // Réchauffe les caches (permissions, paramètres).
        $one = $queries($this->first);
        $many = Order::factory()->create(['business_day_id' => $this->regionDay->business_day_id, 'client_id' => $this->b->id, 'submitted_by' => $this->accountB->id]);
        OrderLine::factory()->for($many)->count(8)->create(['product_id' => fn () => Product::factory()]);
        OrderStatusHistory::create(['order_id' => $many->id, 'to_status' => OrderStatus::Submitted, 'version' => 1, 'user_id' => $this->accountB->id, 'created_at' => now()]);

        expect($queries($many))->toBe($one)
            ->and(($this->show)($many)->json('lines.7.context'))->toBe([
                'limit' => null, 'client_validated' => 0, 'region_quota' => 0, 'region_validated' => 0, 'region_remaining' => 0,
            ]);
    });
});
