<?php

use App\Domain\Access\Role;
use App\Domain\Orders\AdvOrders;
use App\Domain\Orders\ClientOrders;
use App\Domain\Orders\OrderActor;
use App\Domain\Orders\OrderDecisions;
use App\Domain\Orders\OrderStatus;
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
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionDay;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

// File des commandes validées de l'ADV : GET /adv/orders et
// GET /adv/orders/{id} (EF-ADV-04 ; contrat du lot 6, §5) — travail en
// cours sur toutes les journées par heure de validation, onglets par statut
// et leurs compteurs (mêmes filtres, hors statut), filtres, tris, pagination,
// droits d'action de l'appelant ; détail avec le client, les lignes, leurs
// motifs, l'historique nommé.

beforeEach(function () {
    Event::fake([
        QuotasPublished::class, QuotaDraftsSaved::class, LimitDraftsSaved::class, DistributionPublished::class, CutoffExtended::class,
        DayClosed::class, OrderSubmitted::class, OrderCancelled::class, OrdersExpired::class,
        OrderValidated::class, OrdersProcessed::class, OrderShippingUpdated::class, OrderCancelledByAdv::class,
    ]);
    $at = fn (string $moment) => $this->travelTo(CarbonImmutable::parse($moment, 'Africa/Algiers'));
    $this->blida = Depot::factory()->create(['code' => 'BLIDA', 'name' => 'Dépôt de Blida']);
    $this->oran = Depot::factory()->create(['code' => 'ORAN', 'name' => 'Dépôt d\'Oran']);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre', 'depot_id' => $this->blida->id]);
    $this->sud = Region::factory()->create(['code' => 'SUD', 'name' => 'Sud', 'depot_id' => $this->oran->id]);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    $this->a = Client::factory()->create(['code' => 'C0012', 'name' => 'Superette El Baraka', 'region_id' => $this->centre->id]);
    $this->b = Client::factory()->create(['code' => 'C0047', 'name' => 'Alimentation Nour', 'region_id' => $this->centre->id]);
    $this->s = Client::factory()->create(['code' => 'C0201', 'name' => 'Épicerie du Sud', 'region_id' => $this->sud->id]);
    $this->e = Client::factory()->create(['code' => 'C0300', 'name' => 'Marché de l\'Est', 'region_id' => $this->est->id]);
    $this->orange = Product::factory()->create(['sku' => 'P0042', 'name' => 'Jus orange 1 L', 'short_name' => 'Orange 1 L']);
    $this->pomme = Product::factory()->create(['sku' => 'P0057', 'name' => 'Jus pomme 1 L', 'short_name' => 'Pomme 1 L']);
    app(Settings::class)->update(['orders' => ['max_per_day' => 5]], null);
    $this->accounts = [];
    $this->submit = function (Client $client, array $lines, string $day = '2026-10-08'): Order {
        $this->accounts[$client->id] ??= userWithRole(Role::Client, ['client_id' => $client->id]);

        return app(ClientOrders::class)->submit(
            $this->accounts[$client->id], (string) Str::uuid(), BusinessClock::parseDay($day),
            array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines), null,
        )['order'];
    };
    $this->adv = userWithRole(Role::Adv, ['name' => 'Samir ADV']);
    $this->advOrders = app(AdvOrders::class);
    $this->move = fn (OrderStatus $to, Order $order) => $this->advOrders->transition($this->adv, $to, [['id' => $order->public_id, 'version' => $order->fresh()->version]]);

    // Mercredi 7 : une commande du Centre, validée à 09:10.
    $at('2026-10-07 09:00');
    openOrderingDay('2026-10-07', $this->centre, [[$this->orange, 500]]);
    $this->old = ($this->submit)($this->a, [[$this->orange, 6]], '2026-10-07');
    $at('2026-10-07 09:10');
    $this->old = validateOrder($this->old);

    // Jeudi 8.
    $at('2026-10-08 07:00');
    openOrderingDay('2026-10-08', $this->centre, [[$this->orange, 500], [$this->pomme, 300]]);
    openOrderingDay('2026-10-08', $this->sud, [[$this->orange, 200]]);
    openOrderingDay('2026-10-08', $this->est, [[$this->orange, 200]]);
    $this->regional = RegionAssignment::query()->where('region_id', $this->centre->id)->firstOrFail()->user;
    $at('2026-10-08 08:00');
    $this->ob = ($this->submit)($this->b, [[$this->pomme, 4]]);
    $at('2026-10-08 08:20');
    $this->ob = validateOrder($this->ob);
    $at('2026-10-08 08:30');
    $this->oa = ($this->submit)($this->a, [[$this->orange, 40], [$this->pomme, 10]]);
    $at('2026-10-08 08:45');
    $this->oa = validateOrder($this->oa, [$this->pomme->id => 4]);
    $at('2026-10-08 08:50');
    $this->os = ($this->submit)($this->s, [[$this->orange, 20]]);
    $at('2026-10-08 09:00');
    $this->os = validateOrder($this->os);
    $at('2026-10-08 09:05');
    ($this->move)(OrderStatus::InPreparation, $this->os);
    $at('2026-10-08 09:06');
    $this->oe = ($this->submit)($this->e, [[$this->orange, 30]]);
    $at('2026-10-08 09:10');
    $this->oe = validateOrder($this->oe);
    $at('2026-10-08 09:12');
    ($this->move)(OrderStatus::InPreparation, $this->oe);
    $at('2026-10-08 09:14');
    ($this->move)(OrderStatus::Prepared, $this->oe);
    // Expédiée avec un écart : 4 expédiés sur 5, bon de livraison.
    $at('2026-10-08 09:15');
    $this->obs = ($this->submit)($this->b, [[$this->orange, 5]]);
    $at('2026-10-08 09:16');
    $this->obs = validateOrder($this->obs);
    ($this->move)(OrderStatus::InPreparation, $this->obs);
    $this->advOrders->updateShipping($this->adv, $this->obs->fresh(), 3, [['product_id' => $this->orange->id, 'shipped_qty' => 4, 'reason_code' => 'RUPTURE']], 'BL-0042', true);
    ($this->move)(OrderStatus::Prepared, $this->obs);
    ($this->move)(OrderStatus::Shipped, $this->obs);
    // Annulée par l'ADV après sa validation.
    $at('2026-10-08 09:18');
    $this->obc = ($this->submit)($this->b, [[$this->pomme, 2]]);
    $at('2026-10-08 09:19');
    $this->obc = validateOrder($this->obc);
    $this->advOrders->cancel($this->adv, $this->obc, 2, 'DEMANDE_CLIENT', 'Le client a changé d\'avis');
    // Hors de la file : une commande envoyée, une rejetée, une annulée par le client.
    $at('2026-10-08 09:20');
    $this->sent = ($this->submit)($this->e, [[$this->orange, 3]]);
    $rejected = ($this->submit)($this->s, [[$this->orange, 2]]);
    app(OrderDecisions::class)->reject($this->regional, $rejected, 1, 'DOUBLON', null);
    $mine = ($this->submit)($this->s, [[$this->orange, 1]]);
    app(ClientOrders::class)->cancel($this->accounts[$this->s->id], $mine->public_id, 1);

    $at('2026-10-08 09:30');
    $this->orders = [
        'old' => $this->old, 'ob' => $this->ob, 'oa' => $this->oa, 'os' => $this->os, 'oe' => $this->oe, 'obs' => $this->obs, 'obc' => $this->obc,
    ];
    $this->index = fn (string $query = '', ?User $as = null) => $this->actingAs($as ?? $this->adv)->getJson('/api/v1/adv/orders'.$query);
    $this->ids = fn (string $query = '', ?User $as = null): array => ($this->index)($query, $as)->assertOk()->json('data.*.id');
    $this->idsOf = fn (string ...$keys): array => array_map(fn (string $key) => $this->orders[$key]->public_id, $keys);
});

describe('GET /adv/orders', function () {
    it('lists the work in progress of every day by validation time, first validated first (I-90)', function () {
        $response = ($this->index)()->assertOk();

        expect($response->json('data.*.id'))->toBe(($this->idsOf)('old', 'ob', 'oa', 'os', 'oe'))
            ->and($response->json('data.*.status'))->toBe(['VALIDATED', 'VALIDATED', 'VALIDATED', 'IN_PREPARATION', 'PREPARED'])
            ->and($response->json('meta.total'))->toBe(5)
            ->and($response->json('meta.per_page'))->toBe(50);
    });

    it('presents each order of the queue with its client, region, depot, totals and the rights of the caller', function () {
        $oa = $this->oa->fresh();
        $decided = ($this->index)()->assertOk()->json('data.2');

        expect($decided)->toEqual([
            'id' => $oa->public_id,
            'number' => $oa->number,
            'day' => '2026-10-08',
            'status' => 'VALIDATED',
            'status_label' => 'Validée',
            'version' => 2,
            'submitted_at' => '2026-10-08T07:30:00+00:00',
            'decided_at' => '2026-10-08T07:45:00+00:00',
            'decided_by' => ['id' => $this->regional->id, 'name' => $this->regional->name],
            'prep_started_at' => null,
            'prepared_at' => null,
            'shipped_at' => null,
            'delivery_note_ref' => null,
            'client' => ['id' => $this->a->id, 'code' => 'C0012', 'name' => 'Superette El Baraka', 'status' => 'ACTIVE'],
            'region' => ['id' => $this->centre->id, 'code' => 'CENTRE', 'name' => 'Centre'],
            'depot' => ['id' => $this->blida->id, 'code' => 'BLIDA', 'name' => 'Dépôt de Blida'],
            'lines_count' => 2,
            // 40 × 900 + 4 × 900 (la quantité validée, RG-P04) : 39 600 HT, TVA 19 %.
            'total_ht' => 39600.0,
            'total_ttc' => 47124.0,
            'is_adjusted' => true,
            'has_shipping_gap' => false,
            'next_statuses' => ['IN_PREPARATION'],
            'can_cancel' => true,
        ]);
    });

    it('shows the processing stamps, the delivery note and the shipping gap, and no depot for a region without one', function () {
        $shipped = ($this->index)('?filter[status]=SHIPPED')->assertOk()->json('data');
        $prepared = ($this->index)('?filter[status]=PREPARED')->assertOk()->json('data.0');

        expect($shipped)->toHaveCount(1)
            ->and($shipped[0])->toMatchArray([
                'id' => $this->obs->public_id, 'status' => 'SHIPPED', 'delivery_note_ref' => 'BL-0042', 'has_shipping_gap' => true,
                'next_statuses' => [], 'can_cancel' => false,
                'prep_started_at' => '2026-10-08T08:16:00+00:00', 'prepared_at' => '2026-10-08T08:16:00+00:00', 'shipped_at' => '2026-10-08T08:16:00+00:00',
            ])
            ->and($prepared)->toMatchArray([
                'id' => $this->oe->public_id, 'status' => 'PREPARED', 'depot' => null, 'has_shipping_gap' => false,
                'prepared_at' => '2026-10-08T08:14:00+00:00', 'next_statuses' => ['SHIPPED'], 'can_cancel' => false,
            ]);
    });

    it('offers the next step and the cancellation according to the status of each order', function () {
        $byStatus = collect(($this->index)('?filter[status]=VALIDATED,IN_PREPARATION,PREPARED,SHIPPED,CANCELLED')->assertOk()->json('data'))
            ->mapWithKeys(fn (array $order) => [$order['id'] => [$order['next_statuses'], $order['can_cancel']]]);

        expect($byStatus[$this->ob->public_id])->toBe([['IN_PREPARATION'], true])
            ->and($byStatus[$this->os->public_id])->toBe([['PREPARED'], true])
            ->and($byStatus[$this->oe->public_id])->toBe([['SHIPPED'], false])
            ->and($byStatus[$this->obs->public_id])->toBe([[], false])
            ->and($byStatus[$this->obc->public_id])->toBe([[], false]);
    });

    it('gives the Admin the queue in reading only: no next step, no cancellation', function () {
        $data = ($this->index)('', admin())->assertOk()->json('data');

        expect($data)->toHaveCount(5)
            ->and(collect($data)->pluck('next_statuses')->flatten()->all())->toBe([])
            ->and(collect($data)->pluck('can_cancel')->unique()->all())->toBe([false]);
    });

    it('follows the permissions of the ADV for the rights of each order', function () {
        Spatie\Permission\Models\Role::findByName(Role::Adv->value, 'web')->revokePermissionTo('order.cancel-validated');

        $data = ($this->index)()->assertOk()->json('data');
        expect(collect($data)->pluck('can_cancel')->unique()->all())->toBe([false])
            ->and($data[0]['next_statuses'])->toBe(['IN_PREPARATION']);

        Spatie\Permission\Models\Role::findByName(Role::Adv->value, 'web')->revokePermissionTo('order.process');
        expect(collect(($this->index)()->assertOk()->json('data'))->pluck('next_statuses')->flatten()->all())->toBe([]);
    });

    it('counts every tab with the filters of the list but the status', function () {
        $counts = fn (string $query = '') => ($this->index)($query)->assertOk()->json('meta.counts');
        $all = ['VALIDATED' => 3, 'IN_PREPARATION' => 1, 'PREPARED' => 1, 'SHIPPED' => 1, 'CANCELLED' => 1];

        expect($counts())->toBe($all)
            ->and($counts('?filter[status]=SHIPPED'))->toBe($all)
            ->and($counts('?filter[day]=2026-10-07'))->toBe(['VALIDATED' => 1, 'IN_PREPARATION' => 0, 'PREPARED' => 0, 'SHIPPED' => 0, 'CANCELLED' => 0])
            ->and($counts('?filter[region]='.$this->centre->id.'&filter[status]=PREPARED'))->toBe(['VALIDATED' => 3, 'IN_PREPARATION' => 0, 'PREPARED' => 0, 'SHIPPED' => 1, 'CANCELLED' => 1])
            ->and($counts('?filter[client]='.$this->b->id))->toBe(['VALIDATED' => 1, 'IN_PREPARATION' => 0, 'PREPARED' => 0, 'SHIPPED' => 1, 'CANCELLED' => 1]);
        // Ni les commandes envoyées, rejetées ou annulées par le client, ni Livrée ne sont comptées.
        expect(array_sum($counts()))->toBe(7);
    });

    it('filters by status, case insensitive, as a list or a repeated parameter', function () {
        expect(($this->ids)('?filter[status]=shipped'))->toBe(($this->idsOf)('obs'))
            ->and(($this->ids)('?filter[status]=Cancelled'))->toBe(($this->idsOf)('obc'))
            ->and(($this->ids)('?filter[status]=in_preparation,prepared'))->toBe(($this->idsOf)('os', 'oe'))
            ->and(($this->ids)('?filter[status][]=PREPARED&filter[status][]=SHIPPED'))->toBe(($this->idsOf)('oe', 'obs'));
    });

    it('filters by day, region, depot, client, product and search', function () {
        expect(($this->ids)('?filter[day]=2026-10-07'))->toBe(($this->idsOf)('old'))
            ->and(($this->ids)('?filter[day]=2026-10-09'))->toBe([])
            ->and(($this->ids)('?filter[region]='.$this->sud->id))->toBe(($this->idsOf)('os'))
            ->and(($this->ids)('?filter[depot]='.$this->blida->id))->toBe(($this->idsOf)('old', 'ob', 'oa'))
            ->and(($this->ids)('?filter[depot]=none'))->toBe(($this->idsOf)('oe'))
            ->and(($this->ids)('?filter[depot]=NONE'))->toBe(($this->idsOf)('oe'))
            ->and(($this->ids)('?filter[client]='.$this->a->id))->toBe(($this->idsOf)('old', 'oa'))
            ->and(($this->ids)('?filter[product]='.$this->pomme->id))->toBe(($this->idsOf)('ob', 'oa'))
            ->and(($this->ids)('?filter[client]='.$this->a->id.'&filter[product]='.$this->pomme->id.'&filter[day]=2026-10-08'))->toBe(($this->idsOf)('oa'))
            // Numéro, code ou nom du client, casse indifférente.
            ->and(($this->ids)('?filter[search]='.$this->oe->fresh()->number))->toBe(($this->idsOf)('oe'))
            ->and(($this->ids)('?filter[search]=c0201'))->toBe(($this->idsOf)('os'))
            ->and(($this->ids)('?filter[search]=nour'))->toBe(($this->idsOf)('ob'))
            ->and(($this->ids)('?filter[search]=261008'))->toBe(($this->idsOf)('ob', 'oa', 'os', 'oe'))
            ->and(($this->ids)('?filter[search]=introuvable'))->toBe([]);
    });

    it('reads the wildcards of a search as plain text', function () {
        expect(($this->ids)('?filter[search]=%25'))->toBe([])
            ->and(($this->ids)('?filter[search]=C00_2'))->toBe([])
            ->and(($this->ids)('?filter[search]=C0012'))->toBe(($this->idsOf)('old', 'oa'));
    });

    it('sorts by validation time (default, first validated first), number and submission time', function () {
        expect(($this->ids)('?sort=decided_at'))->toBe(($this->idsOf)('old', 'ob', 'oa', 'os', 'oe'))
            ->and(($this->ids)('?sort=-decided_at'))->toBe(($this->idsOf)('oe', 'os', 'oa', 'ob', 'old'))
            ->and(($this->ids)('?sort=number'))->toBe(Order::query()->whereIn('id', array_map(fn (Order $o) => $o->id, [$this->old, $this->ob, $this->oa, $this->os, $this->oe]))->orderBy('number')->pluck('public_id')->all())
            ->and(($this->ids)('?sort=-number'))->toBe(array_reverse(($this->ids)('?sort=number')))
            ->and(($this->ids)('?sort=submitted_at'))->toBe(($this->idsOf)('old', 'ob', 'oa', 'os', 'oe'));
    });

    it('paginates and keeps the counts of the whole queue on every page', function () {
        $first = ($this->index)('?per_page=2')->assertOk();
        $last = ($this->index)('?per_page=2&page=3')->assertOk();

        expect($first->json('data.*.id'))->toBe(($this->idsOf)('old', 'ob'))
            ->and($first->json('meta.last_page'))->toBe(3)
            ->and($first->json('meta.total'))->toBe(5)
            ->and($first->json('meta.per_page'))->toBe(2)
            ->and($last->json('data.*.id'))->toBe(($this->idsOf)('oe'))
            ->and($last->json('meta.counts.VALIDATED'))->toBe(3)
            ->and(($this->index)('?per_page=500')->json('meta.per_page'))->toBe(100);
    });

    it('answers 422 to an invalid parameter, an unknown filter or a sort', function (string $query, string $field) {
        $response = ($this->index)($query);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => [$field]]);
    })->with([
        'journée mal formée' => ['?filter[day]=08/10/2026', 'filter.day'],
        'journée inexistante' => ['?filter[day]=2026-02-30', 'filter.day'],
        'statut inconnu' => ['?filter[status]=VALIDATED,DRAFT', 'filter.status'],
        'commande envoyée' => ['?filter[status]=SUBMITTED', 'filter.status'],
        'commande rejetée' => ['?filter[status]=REJECTED', 'filter.status'],
        'commande expirée' => ['?filter[status]=EXPIRED', 'filter.status'],
        'statut vide dans la liste' => ['?filter[status]=VALIDATED,', 'filter.status'],
        'statut imbriqué' => ['?filter[status][0][]=VALIDATED', 'filter.status'],
        'région mal formée' => ['?filter[region]=centre', 'filter.region'],
        'plusieurs régions' => ['?filter[region]=1,2', 'filter.region'],
        'région en tableau' => ['?filter[region][]=1', 'filter.region'],
        'dépôt mal formé' => ['?filter[depot]=blida', 'filter.depot'],
        'dépôt nul' => ['?filter[depot]=0', 'filter.depot'],
        'client mal formé' => ['?filter[client]=C0012', 'filter.client'],
        'produit mal formé' => ['?filter[product]=P0042', 'filter.product'],
        'recherche trop longue' => ['?filter[search]='.str_repeat('a', 101), 'filter.search'],
        'filtre inconnu' => ['?filter[number]=1', 'filter'],
        'filtre sans nom' => ['?filter=VALIDATED', 'filter'],
        'tri inconnu' => ['?sort=client', 'sort'],
        'tri décroissant sur l\'envoi' => ['?sort=-submitted_at', 'sort'],
    ]);

    it('reads a bounded number of queries, whatever the number of orders', function () {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            ($this->index)('?filter[status]=VALIDATED,IN_PREPARATION,PREPARED,SHIPPED,CANCELLED')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $queries(); // Réchauffe les caches (permissions, paramètres, motifs).
        $few = $queries();
        $regionDay = RegionDay::query()->where('region_id', $this->centre->id)->whereHas('businessDay', fn ($q) => $q->where('day', '2026-10-08'))->firstOrFail();
        foreach (Client::factory()->count(4)->create(['region_id' => $this->centre->id]) as $client) {
            $order = Order::factory()->shipped('BL-9')->withLines([[$this->orange, 5, 5], [$this->pomme, 3, 2]])->create([
                'business_day_id' => $regionDay->business_day_id, 'client_id' => $client->id, 'decided_by' => $this->regional->id,
            ]);
            $order->lines()->where('product_id', $this->pomme->id)->update(['shipped_qty' => 1, 'shipping_gap_reason_code' => 'CASSE']);
            Order::factory()->validated()->withLines([[$this->orange, 5, 5]])->create(['business_day_id' => $regionDay->business_day_id, 'client_id' => $client->id, 'decided_by' => $this->regional->id]);
        }

        expect($queries())->toBe($few)
            ->and(($this->index)('?filter[status]=SHIPPED&per_page=100')->json('meta.total'))->toBe(5);
    });
});

describe('GET /adv/orders/{id}', function () {
    beforeEach(function () {
        $this->show = fn (Order $order, ?User $as = null) => $this->actingAs($as ?? $this->adv)->getJson("/api/v1/adv/orders/{$order->public_id}");
    });

    it('shows an adjusted order: client identity, lines with their reasons, history with every author, rights', function () {
        $this->a->update(['address' => '12 rue Didouche Mourad', 'wilaya' => 'Alger', 'commune' => 'Alger-Centre', 'phone' => '0550123456', 'phone2' => null, 'nif' => '099916010123456', 'rc' => '16/00-1234567B16']);
        $oa = $this->oa->fresh();
        $oa->forceFill(['client_comment' => 'Livraison avant 10 h'])->save();
        $oa->lines()->where('product_id', $this->pomme->id)->update(['adjustment_comment' => 'Reste région limité']);
        $regional = $this->regional;

        $response = ($this->show)($oa)->assertOk();

        expect($response->json())->toMatchArray([
            'id' => $oa->public_id,
            'status' => 'VALIDATED',
            'is_adjusted' => true,
            'client' => [
                'id' => $this->a->id, 'code' => 'C0012', 'name' => 'Superette El Baraka', 'status' => 'ACTIVE',
                'address' => '12 rue Didouche Mourad', 'wilaya' => 'Alger', 'commune' => 'Alger-Centre',
                'phone' => '0550123456', 'phone2' => null, 'nif' => '099916010123456', 'rc' => '16/00-1234567B16',
            ],
            'comment' => 'Livraison avant 10 h',
            'total_vat' => 7524.0,
            'decision_reason' => null,
            'decision_comment' => null,
            'can' => ['next_statuses' => ['IN_PREPARATION'], 'edit_shipping_lines' => false, 'edit_delivery_note' => false, 'cancel' => true, 'print' => true],
        ])
            ->and(array_keys($response->json()))->toContain('lines', 'history', 'depot', 'region', 'decided_by', 'next_statuses', 'can_cancel');
        $lines = $response->json('lines');
        expect($lines)->toHaveCount(2)
            ->and($lines[1])->toMatchArray([
                'product_id' => $this->pomme->id, 'sku' => 'P0057', 'requested_qty' => 10, 'validated_qty' => 4, 'shipped_qty' => null,
                'unit_price_ht' => 900.0, 'amount_ht' => 3600.0,
                'adjustment_reason_code' => 'QUOTA_INSUFFISANT', 'adjustment_reason' => ['code' => 'QUOTA_INSUFFISANT', 'label' => 'Quota insuffisant'],
                'adjustment_comment' => 'Reste région limité', 'shipping_gap_reason' => null,
            ])
            ->and($lines[0])->toMatchArray(['validated_qty' => 40, 'adjustment_reason' => null, 'adjustment_comment' => null]);
        // L'historique nomme tous les auteurs : client, régional.
        expect($response->json('history'))->toHaveCount(2)
            ->and($response->json('history.0'))->toMatchArray(['from' => null, 'to' => 'SUBMITTED', 'actor' => 'CLIENT', 'by_name' => $this->accounts[$this->a->id]->name])
            ->and($response->json('history.1'))->toMatchArray(['from' => 'SUBMITTED', 'to' => 'VALIDATED', 'actor' => 'REGIONAL', 'by_name' => $regional->name, 'version' => 2]);
    });

    it('shows the shipping gap with its reason, the delivery note and the history of the processing', function () {
        $response = ($this->show)($this->obs)->assertOk();

        expect($response->json('status'))->toBe('SHIPPED')
            ->and($response->json('delivery_note_ref'))->toBe('BL-0042')
            ->and($response->json('has_shipping_gap'))->toBeTrue()
            ->and($response->json('lines.0'))->toMatchArray([
                'validated_qty' => 5, 'shipped_qty' => 4, 'shipping_gap_reason' => ['code' => 'RUPTURE', 'label' => 'Rupture de stock'],
            ])
            ->and($response->json('can'))->toBe(['next_statuses' => [], 'edit_shipping_lines' => false, 'edit_delivery_note' => true, 'cancel' => false, 'print' => true])
            ->and($response->json('history.*.to'))->toBe(['SUBMITTED', 'VALIDATED', 'IN_PREPARATION', 'PREPARED', 'SHIPPED'])
            ->and($response->json('history.2'))->toMatchArray(['actor' => 'ADV', 'by_name' => 'Samir ADV']);
    });

    it('lets the shipping quantities and the delivery note be edited while in preparation', function () {
        expect(($this->show)($this->os)->json('can'))->toBe(['next_statuses' => ['PREPARED'], 'edit_shipping_lines' => true, 'edit_delivery_note' => true, 'cancel' => true, 'print' => true])
            ->and(($this->show)($this->oe)->json('can'))->toBe(['next_statuses' => ['SHIPPED'], 'edit_shipping_lines' => true, 'edit_delivery_note' => true, 'cancel' => false, 'print' => true]);
    });

    it('shows an order cancelled by the ADV with the reason and comment of the cancellation (I-75)', function () {
        $response = ($this->show)($this->obc)->assertOk();

        expect($response->json('status'))->toBe('CANCELLED')
            ->and($response->json('decision_reason'))->toBe(['code' => 'DEMANDE_CLIENT', 'label' => 'À la demande du client'])
            ->and($response->json('decision_comment'))->toBe('Le client a changé d\'avis')
            // La validation reste.
            ->and($response->json('decided_by.id'))->toBe($this->regional->id)
            ->and($response->json('lines.0.validated_qty'))->toBe(2)
            ->and($response->json('history.*.to'))->toBe(['SUBMITTED', 'VALIDATED', 'CANCELLED'])
            ->and($response->json('history.2'))->toMatchArray(['actor' => 'ADV', 'comment' => 'Le client a changé d\'avis'])
            ->and($response->json('can'))->toBe(['next_statuses' => [], 'edit_shipping_lines' => false, 'edit_delivery_note' => false, 'cancel' => false, 'print' => true]);
    });

    it('shows the Admin the detail with no right of action, not even printing (I-87)', function () {
        $response = ($this->show)($this->oa, admin())->assertOk();

        expect($response->json('can'))->toBe(['next_statuses' => [], 'edit_shipping_lines' => false, 'edit_delivery_note' => false, 'cancel' => false, 'print' => false])
            ->and($response->json('next_statuses'))->toBe([])
            ->and($response->json('can_cancel'))->toBeFalse();
    });

    it('reads a bounded number of queries, whatever the number of lines and history entries', function () {
        $queries = function (Order $order): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            ($this->show)($order)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $queries($this->oa); // Réchauffe les caches.
        $few = $queries($this->oa);
        $big = Order::factory()->inPreparation()->create(['business_day_id' => $this->oa->business_day_id, 'client_id' => $this->a->id, 'decided_by' => $this->regional->id]);
        foreach (Product::factory()->count(8)->create() as $product) {
            $big->lines()->create(['product_id' => $product->id, 'requested_qty' => 5, 'validated_qty' => 4, 'unit_price_ht' => '10.00', 'vat_rate' => '19.00', 'adjustment_reason_code' => 'BORNE_ATTEINTE']);
        }
        OrderStatusHistory::create(['order_id' => $big->id, 'from_status' => null, 'to_status' => OrderStatus::Submitted, 'version' => 1, 'user_id' => $this->regional->id, 'actor' => OrderActor::Regional, 'created_at' => now()]);

        expect($queries($big))->toBe($few);
    });
});
