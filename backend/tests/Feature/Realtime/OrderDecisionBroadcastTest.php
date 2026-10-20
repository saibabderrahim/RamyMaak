<?php

use App\Domain\Access\Role;
use App\Domain\Orders\AdvOrders;
use App\Domain\Orders\ClientOrders;
use App\Domain\Orders\OrderDecisions;
use App\Domain\Orders\OrderStatus;
use App\Events\OrderCancelledByAdv;
use App\Events\OrderRejected;
use App\Events\OrderShippingUpdated;
use App\Events\OrdersProcessed;
use App\Events\OrderValidated;
use App\Events\Realtime\AdvOrderCancelledByAdv;
use App\Events\Realtime\AdvOrderShippingUpdated;
use App\Events\Realtime\AdvOrderValidated;
use App\Events\Realtime\OrderCancelled as RealtimeOrderCancelled;
use App\Events\Realtime\OrderCancelledByAdv as RealtimeOrderCancelledByAdv;
use App\Events\Realtime\OrderRejected as RealtimeOrderRejected;
use App\Events\Realtime\OrdersExpired as RealtimeOrdersExpired;
use App\Events\Realtime\OrdersProcessed as RealtimeOrdersProcessed;
use App\Events\Realtime\OrderStatusChanged;
use App\Events\Realtime\OrderSubmitted as RealtimeOrderSubmitted;
use App\Events\Realtime\OrderUpdated as RealtimeOrderUpdated;
use App\Events\Realtime\OrderValidated as RealtimeOrderValidated;
use App\Listeners\BroadcastOrderActivity;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Diffusion temps réel des décisions et du traitement (§9.6 ; contrat du
// lot 6, §7, I-80, I-81, I-97) : OrderValidated, OrderRejected et
// OrderCancelledByAdv sur region-day.{r}.{day} (avec le client),
// OrderStatusChanged sur client.{c}, OrderValidated, OrderCancelledByAdv,
// OrderShippingUpdated et OrdersProcessed sur adv.{day} (identifiants seuls),
// OrdersProcessed une fois par journée régionale. Aucune quantité (EF-NOT-03).

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:30 à Alger : commande jusqu'à 13:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    $this->ouest = Region::factory()->create(['code' => 'OUEST', 'name' => 'Ouest']);
    $this->a = Client::factory()->create(['code' => 'C0012', 'name' => 'Superette El Baraka', 'region_id' => $this->centre->id]);
    $this->b = Client::factory()->create(['code' => 'C0047', 'region_id' => $this->centre->id]);
    $this->accountA = userWithRole(Role::Client, ['client_id' => $this->a->id]);
    $this->accountB = userWithRole(Role::Client, ['client_id' => $this->b->id]);
    $this->orange = Product::factory()->create(['sku' => 'P0042']);
    $this->regionDay = openOrderingDay('2026-10-08', $this->centre, [[$this->orange, 500]]);
    $this->estDay = openOrderingDay('2026-10-08', $this->est, [[$this->orange, 300]]);
    $this->ouestDay = openOrderingDay('2026-10-08', $this->ouest, [[$this->orange, 300]]);
    $this->businessDay = $this->regionDay->businessDay;
    $this->regional = RegionAssignment::query()->where('region_id', $this->centre->id)->sole()->user;
    $this->adv = userWithRole(Role::Adv);

    $this->accounts = [$this->a->id => $this->accountA, $this->b->id => $this->accountB];
    $this->submit = fn (Client $client, int $quantity = 40) => app(ClientOrders::class)->submit(
        $this->accounts[$client->id],
        (string) Str::uuid(),
        BusinessClock::parseDay('2026-10-08'),
        [['product_id' => $this->orange->id, 'quantity' => $quantity]],
        null,
    )['order'];
    $this->reject = fn (Order $order) => app(OrderDecisions::class)->reject($this->regional, $order, $order->fresh()->version, 'QUOTA_EPUISE', null);
    $this->advOrders = app(AdvOrders::class);
    $this->move = fn (OrderStatus $to, array $orders) => $this->advOrders->transition(
        $this->adv,
        $to,
        array_map(fn (Order $order) => ['id' => $order->public_id, 'version' => $order->fresh()->version], $orders),
    );
    $this->realtime = [
        RealtimeOrderSubmitted::class, RealtimeOrderUpdated::class, RealtimeOrderCancelled::class, OrderStatusChanged::class, RealtimeOrdersExpired::class,
        RealtimeOrderValidated::class, RealtimeOrderRejected::class, RealtimeOrderCancelledByAdv::class, RealtimeOrdersProcessed::class,
        AdvOrderValidated::class, AdvOrderCancelledByAdv::class, AdvOrderShippingUpdated::class,
    ];
    $this->channels = fn (ShouldBroadcast $event) => array_map(fn ($channel) => $channel->name, $event->broadcastOn());
    $this->dispatched = fn (string $class) => Event::dispatched($class)->map(fn (array $call) => $call[0])->values();
    $this->regionChannel = fn (Region $region) => "private-region-day.{$region->id}.2026-10-08";
    $this->clientChannel = fn (Client $client) => "private-client.{$client->id}";
    $this->advChannel = 'private-adv.2026-10-08';
    // Diffuseur de test : enregistre ce qui partirait vers Reverb, ou échoue
    // pour les événements donnés (Reverb injoignable).
    $this->recorder = function (array $failingEvents = []): Broadcaster {
        $recorder = new class($failingEvents) extends Broadcaster
        {
            /** @var list<array{channels: list<string>, event: string, payload: array<string, mixed>}> */
            public array $sent = [];

            /** @param  list<string>  $failing */
            public function __construct(private readonly array $failing) {}

            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                if (in_array($event, $this->failing, true)) {
                    throw new RuntimeException("Reverb injoignable ({$event})");
                }
                unset($payload['socket']);
                $this->sent[] = ['channels' => $this->formatChannels($channels), 'event' => $event, 'payload' => $payload];
            }
        };
        Broadcast::extend('recording', fn () => $recorder);
        config(['broadcasting.default' => 'recording', 'broadcasting.connections.recording' => ['driver' => 'recording']]);

        return $recorder;
    };
    // Commandes validées de régions et de clients différents, créées directement
    // (traitement en masse) : [région, nombre] ; chaque commande a son client.
    $this->bulk = function (array $perRegion, ?int $businessDayId = null): array {
        $orders = [];
        foreach ($perRegion as [$region, $count]) {
            foreach (Client::factory()->count($count)->create(['region_id' => $region->id]) as $client) {
                $orders[] = Order::factory()->validated()->create([
                    'business_day_id' => $businessDayId ?? $this->businessDay->id,
                    'region_id' => $region->id,
                    'client_id' => $client->id,
                    'version' => 2,
                ]);
            }
        }

        return $orders;
    };
});

it('broadcasts a validation to the region day with the client, to the client and, by identifiers only, to the ADV day (N-08, I-81)', function () {
    $order = ($this->submit)($this->a, 40);
    Event::fake($this->realtime);

    validateOrder($order, [$this->orange->id => 30]);

    $region = ($this->dispatched)(RealtimeOrderValidated::class);
    $client = ($this->dispatched)(OrderStatusChanged::class);
    $adv = ($this->dispatched)(AdvOrderValidated::class);
    expect($region)->toHaveCount(1)
        ->and(($this->channels)($region[0]))->toBe([($this->regionChannel)($this->centre)])
        ->and($region[0]->broadcastAs())->toBe('OrderValidated')
        ->and($region[0]->broadcastWith())->toBe([
            'id' => $order->public_id, 'number' => $order->number, 'status' => 'VALIDATED', 'version' => 2, 'day' => '2026-10-08', 'client_id' => $this->a->id,
        ])
        ->and($client)->toHaveCount(1)
        ->and(($this->channels)($client[0]))->toBe([($this->clientChannel)($this->a)])
        ->and($client[0]->broadcastAs())->toBe('OrderStatusChanged')
        ->and($client[0]->broadcastWith())->toBe([
            'id' => $order->public_id, 'number' => $order->number, 'status' => 'VALIDATED', 'version' => 2, 'day' => '2026-10-08',
        ])
        ->and($adv)->toHaveCount(1)
        ->and(($this->channels)($adv[0]))->toBe([$this->advChannel])
        ->and($adv[0]->broadcastAs())->toBe('OrderValidated')
        // Identifiants seuls : ni numéro, ni région, ni client, ni quantité.
        ->and($adv[0]->broadcastWith())->toBe(['id' => $order->public_id, 'status' => 'VALIDATED', 'version' => 2, 'day' => '2026-10-08']);
    Event::assertNotDispatched(RealtimeOrderRejected::class);
    Event::assertNotDispatched(RealtimeOrderCancelledByAdv::class);
    Event::assertNotDispatched(RealtimeOrdersProcessed::class);
});

it('broadcasts a rejection to the region day with the client and to the client, nothing to the ADV', function () {
    $order = ($this->submit)($this->a);
    Event::fake($this->realtime);

    ($this->reject)($order);

    $region = ($this->dispatched)(RealtimeOrderRejected::class);
    $client = ($this->dispatched)(OrderStatusChanged::class);
    expect($region)->toHaveCount(1)
        ->and(($this->channels)($region[0]))->toBe([($this->regionChannel)($this->centre)])
        ->and($region[0]->broadcastAs())->toBe('OrderRejected')
        ->and($region[0]->broadcastWith())->toBe([
            'id' => $order->public_id, 'number' => $order->number, 'status' => 'REJECTED', 'version' => 2, 'day' => '2026-10-08', 'client_id' => $this->a->id,
        ])
        ->and($client)->toHaveCount(1)
        ->and(($this->channels)($client[0]))->toBe([($this->clientChannel)($this->a)])
        ->and($client[0]->broadcastWith())->toBe([
            'id' => $order->public_id, 'number' => $order->number, 'status' => 'REJECTED', 'version' => 2, 'day' => '2026-10-08',
        ]);
    Event::assertNotDispatched(AdvOrderValidated::class);
    Event::assertNotDispatched(AdvOrderCancelledByAdv::class);
    Event::assertNotDispatched(RealtimeOrderValidated::class);
});

it('broadcasts an ADV cancellation as OrderCancelledByAdv to the region day and the ADV day, never as a cancellation by the client', function () {
    $order = ($this->submit)($this->a);
    validateOrder($order);
    Event::fake($this->realtime);

    $this->advOrders->cancel($this->adv, $order, 2, 'RUPTURE', 'Rupture constatée');

    $region = ($this->dispatched)(RealtimeOrderCancelledByAdv::class);
    $client = ($this->dispatched)(OrderStatusChanged::class);
    $adv = ($this->dispatched)(AdvOrderCancelledByAdv::class);
    expect($region)->toHaveCount(1)
        ->and(($this->channels)($region[0]))->toBe([($this->regionChannel)($this->centre)])
        ->and($region[0]->broadcastAs())->toBe('OrderCancelledByAdv')
        ->and($region[0]->broadcastWith())->toBe([
            'id' => $order->public_id, 'number' => $order->number, 'status' => 'CANCELLED', 'version' => 3, 'day' => '2026-10-08', 'client_id' => $this->a->id,
        ])
        ->and($client)->toHaveCount(1)
        ->and(($this->channels)($client[0]))->toBe([($this->clientChannel)($this->a)])
        ->and($client[0]->broadcastWith())->toBe([
            'id' => $order->public_id, 'number' => $order->number, 'status' => 'CANCELLED', 'version' => 3, 'day' => '2026-10-08',
        ])
        ->and($adv)->toHaveCount(1)
        ->and(($this->channels)($adv[0]))->toBe([$this->advChannel])
        ->and($adv[0]->broadcastAs())->toBe('OrderCancelledByAdv')
        ->and($adv[0]->broadcastWith())->toBe(['id' => $order->public_id, 'status' => 'CANCELLED', 'version' => 3, 'day' => '2026-10-08']);
    // Pas l'annulation par le client : ni N-06 ni OrderCancelled.
    Event::assertNotDispatched(RealtimeOrderCancelled::class);
});

it('broadcasts a shipping update to the ADV day only, with the status read, and nothing when nothing changes', function () {
    $order = ($this->submit)($this->a);
    validateOrder($order);
    ($this->move)(OrderStatus::InPreparation, [$order]);
    Event::fake($this->realtime);

    $this->advOrders->updateShipping($this->adv, $order, 3, [['product_id' => $this->orange->id, 'shipped_qty' => 30, 'reason_code' => 'RUPTURE']], 'BL-0042', true);
    // Rien de changé : ni version, ni événement.
    $this->advOrders->updateShipping($this->adv, $order->fresh(), 4, [['product_id' => $this->orange->id, 'shipped_qty' => 30, 'reason_code' => 'RUPTURE']], 'BL-0042', true);

    $events = ($this->dispatched)(AdvOrderShippingUpdated::class);
    expect($events)->toHaveCount(1)
        ->and(($this->channels)($events[0]))->toBe([$this->advChannel])
        ->and($events[0]->broadcastAs())->toBe('OrderShippingUpdated')
        ->and($events[0]->broadcastWith())->toBe(['id' => $order->public_id, 'status' => 'IN_PREPARATION', 'version' => 4, 'day' => '2026-10-08']);
    // Ni la région ni le client ne relisent leurs écrans pour une saisie d'expédition.
    foreach ([RealtimeOrderValidated::class, OrderStatusChanged::class, RealtimeOrdersProcessed::class, AdvOrderValidated::class] as $class) {
        Event::assertNotDispatched($class);
    }
});

it('broadcasts one OrdersProcessed per region day and one per day for a bulk change of 100 orders, and OrderStatusChanged to each client (I-97)', function () {
    $orders = ($this->bulk)([[$this->centre, 50], [$this->est, 30], [$this->ouest, 20]]);
    Event::fake($this->realtime);

    $result = $this->advOrders->transition($this->adv, OrderStatus::InPreparation, array_map(
        fn (Order $order) => ['id' => $order->public_id, 'version' => 2],
        $orders,
    ));

    expect($result->applied)->toHaveCount(100);
    $regions = ($this->dispatched)(RealtimeOrdersProcessed::class)->filter(fn (RealtimeOrdersProcessed $e) => $e->regionId !== null)->values();
    $advDay = ($this->dispatched)(RealtimeOrdersProcessed::class)->filter(fn (RealtimeOrdersProcessed $e) => $e->regionId === null)->values();
    expect($regions)->toHaveCount(3)
        ->and($regions->map(fn (RealtimeOrdersProcessed $e) => ($this->channels)($e))->all())->toBe([
            [($this->regionChannel)($this->centre)], [($this->regionChannel)($this->est)], [($this->regionChannel)($this->ouest)],
        ])
        ->and($regions->map(fn (RealtimeOrdersProcessed $e) => $e->broadcastWith())->all())->toBe([
            ['day' => '2026-10-08', 'status' => 'IN_PREPARATION', 'count' => 50],
            ['day' => '2026-10-08', 'status' => 'IN_PREPARATION', 'count' => 30],
            ['day' => '2026-10-08', 'status' => 'IN_PREPARATION', 'count' => 20],
        ])
        ->and($regions[0]->broadcastAs())->toBe('OrdersProcessed')
        ->and($advDay)->toHaveCount(1)
        ->and(($this->channels)($advDay[0]))->toBe([$this->advChannel])
        ->and($advDay[0]->broadcastAs())->toBe('OrdersProcessed')
        ->and($advDay[0]->broadcastWith())->toBe(['day' => '2026-10-08', 'status' => 'IN_PREPARATION', 'count' => 100]);

    // Chaque client prévenu sur son canal, à la version atteinte.
    $clients = ($this->dispatched)(OrderStatusChanged::class);
    expect($clients)->toHaveCount(100)
        ->and($clients->map(fn (OrderStatusChanged $e) => ($this->channels)($e)[0])->unique()->count())->toBe(100)
        ->and(($this->channels)($clients[0]))->toBe(["private-client.{$orders[0]->client_id}"])
        ->and($clients[0]->broadcastWith())->toBe([
            'id' => $orders[0]->public_id, 'number' => $orders[0]->number, 'status' => 'IN_PREPARATION', 'version' => 3, 'day' => '2026-10-08',
        ]);
    // Rien d'autre : ni un événement par commande sur la région ou l'ADV.
    foreach ([RealtimeOrderValidated::class, AdvOrderValidated::class, AdvOrderShippingUpdated::class] as $class) {
        Event::assertNotDispatched($class);
    }
});

it('broadcasts one OrdersProcessed per day on the ADV channels when a bulk change spans two days', function () {
    $tomorrow = openOrderingDay('2026-10-09', $this->centre, [[$this->orange, 100]])->businessDay;
    $today = ($this->bulk)([[$this->centre, 2], [$this->est, 1]]);
    $next = ($this->bulk)([[$this->centre, 3]], $tomorrow->id);
    Event::fake($this->realtime);

    $this->advOrders->transition($this->adv, OrderStatus::InPreparation, array_map(
        fn (Order $order) => ['id' => $order->public_id, 'version' => 2],
        [...$next, ...$today],
    ));

    $processed = ($this->dispatched)(RealtimeOrdersProcessed::class);
    expect($processed->map(fn (RealtimeOrdersProcessed $e) => [($this->channels)($e)[0], $e->broadcastWith()['count']])->all())->toBe([
        ["private-region-day.{$this->centre->id}.2026-10-08", 2],
        ["private-region-day.{$this->est->id}.2026-10-08", 1],
        ["private-region-day.{$this->centre->id}.2026-10-09", 3],
        ['private-adv.2026-10-08', 3],
        ['private-adv.2026-10-09', 3],
    ])
        ->and(($this->dispatched)(OrderStatusChanged::class))->toHaveCount(6);
});

it('reads a bulk change of 100 orders with a bounded number of queries', function () {
    $orders = ($this->bulk)([[$this->centre, 100]]);
    Event::fake($this->realtime);
    $event = new OrdersProcessed(
        array_map(fn (Order $order) => [
            'orderId' => $order->id, 'clientId' => $order->client_id, 'regionId' => $order->region_id, 'businessDayId' => $order->business_day_id, 'version' => 3,
        ], $orders),
        OrderStatus::Shipped,
        $this->adv->id,
        'BATCH',
    );

    DB::enableQueryLog();
    app(BroadcastOrderActivity::class)->handleOrdersProcessed($event);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Les journées, puis les commandes : indépendant du nombre de commandes.
    expect($queries)->toBeLessThanOrEqual(3)
        ->and(($this->dispatched)(OrderStatusChanged::class))->toHaveCount(100)
        ->and(($this->dispatched)(RealtimeOrdersProcessed::class))->toHaveCount(2);
});

it('relays the new events from the queue, never during the request', function () {
    foreach ([
        RealtimeOrderValidated::class, RealtimeOrderRejected::class, RealtimeOrderCancelledByAdv::class, RealtimeOrdersProcessed::class,
        AdvOrderValidated::class, AdvOrderCancelledByAdv::class, AdvOrderShippingUpdated::class,
    ] as $class) {
        expect(is_subclass_of($class, ShouldBroadcast::class))->toBeTrue()
            ->and(is_subclass_of($class, ShouldBroadcastNow::class))->toBeFalse();
    }

    $validated = ($this->submit)($this->a);
    $rejected = ($this->submit)($this->b);
    Queue::fake();
    validateOrder($validated);
    ($this->reject)($rejected);
    ($this->move)(OrderStatus::InPreparation, [$validated]);
    $this->advOrders->updateShipping($this->adv, $validated->fresh(), 3, null, 'BL-1', true);
    $this->advOrders->cancel($this->adv, $validated->fresh(), 4, 'RUPTURE', null);

    $pushed = fn (string $class, int $times) => Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job) => $job->event instanceof $class, $times);
    $pushed(RealtimeOrderValidated::class, 1);
    $pushed(RealtimeOrderRejected::class, 1);
    $pushed(RealtimeOrderCancelledByAdv::class, 1);
    $pushed(AdvOrderValidated::class, 1);
    $pushed(AdvOrderCancelledByAdv::class, 1);
    $pushed(AdvOrderShippingUpdated::class, 1);
    // Un par région, un pour l'ADV : un seul changement de statut ici.
    $pushed(RealtimeOrdersProcessed::class, 2);
    // Validation, rejet, préparation, annulation par l'ADV.
    $pushed(OrderStatusChanged::class, 4);
});

it('delivers the decision events to the broadcaster with identifiers only, never a quantity or an amount', function () {
    $recorder = ($this->recorder)();
    $validated = ($this->submit)($this->a, 40);
    $rejected = ($this->submit)($this->b);
    $recorder->sent = [];

    validateOrder($validated, [$this->orange->id => 30]);
    ($this->reject)($rejected);
    ($this->move)(OrderStatus::InPreparation, [$validated]);
    $this->advOrders->updateShipping($this->adv, $validated->fresh(), 3, [['product_id' => $this->orange->id, 'shipped_qty' => 25, 'reason_code' => 'RUPTURE']], null, false);
    $this->advOrders->cancel($this->adv, $validated->fresh(), 4, 'RUPTURE', null);

    $sent = collect($recorder->sent)->filter(fn (array $sent) => ! str_contains($sent['event'], 'Notification'))->values();
    $identity = ['id' => $validated->public_id, 'number' => $validated->number];
    $day = ['day' => '2026-10-08'];
    expect($sent->where('event', 'OrderValidated')->values()->all())->toBe([
        ['channels' => [($this->regionChannel)($this->centre)], 'event' => 'OrderValidated', 'payload' => [...$identity, 'status' => 'VALIDATED', 'version' => 2, ...$day, 'client_id' => $this->a->id]],
        ['channels' => [$this->advChannel], 'event' => 'OrderValidated', 'payload' => ['id' => $validated->public_id, 'status' => 'VALIDATED', 'version' => 2, ...$day]],
    ])->and($sent->where('event', 'OrderRejected')->values()->all())->toBe([
        ['channels' => [($this->regionChannel)($this->centre)], 'event' => 'OrderRejected', 'payload' => ['id' => $rejected->public_id, 'number' => $rejected->number, 'status' => 'REJECTED', 'version' => 2, ...$day, 'client_id' => $this->b->id]],
    ])->and($sent->where('event', 'OrderShippingUpdated')->values()->all())->toBe([
        ['channels' => [$this->advChannel], 'event' => 'OrderShippingUpdated', 'payload' => ['id' => $validated->public_id, 'status' => 'IN_PREPARATION', 'version' => 4, ...$day]],
    ])->and($sent->where('event', 'OrdersProcessed')->values()->all())->toBe([
        ['channels' => [($this->regionChannel)($this->centre)], 'event' => 'OrdersProcessed', 'payload' => [...$day, 'status' => 'IN_PREPARATION', 'count' => 1]],
        ['channels' => [$this->advChannel], 'event' => 'OrdersProcessed', 'payload' => [...$day, 'status' => 'IN_PREPARATION', 'count' => 1]],
    ])->and($sent->where('event', 'OrderCancelledByAdv')->values()->all())->toBe([
        ['channels' => [($this->regionChannel)($this->centre)], 'event' => 'OrderCancelledByAdv', 'payload' => [...$identity, 'status' => 'CANCELLED', 'version' => 5, ...$day, 'client_id' => $this->a->id]],
        ['channels' => [$this->advChannel], 'event' => 'OrderCancelledByAdv', 'payload' => ['id' => $validated->public_id, 'status' => 'CANCELLED', 'version' => 5, ...$day]],
    ])->and($sent->where('event', 'OrderStatusChanged')->pluck('payload.status')->all())->toBe(['VALIDATED', 'REJECTED', 'IN_PREPARATION', 'CANCELLED']);

    // Aucune clé de quantité ou de montant, nulle part, et rien sur adv.{day} au-delà des identifiants.
    $keys = $sent->flatMap(fn (array $sent) => array_keys($sent['payload']))->unique()->sort()->values()->all();
    expect($keys)->toBe(['client_id', 'count', 'day', 'id', 'number', 'status', 'version']);
    foreach ($sent->filter(fn (array $sent) => in_array($this->advChannel, $sent['channels'], true)) as $onAdv) {
        expect(array_diff(array_keys($onAdv['payload']), ['id', 'status', 'version', 'day', 'count']))->toBe([]);
    }
});

it('never makes a decision or a status change fail when the realtime relay fails, and keeps relaying the other events', function () {
    Exceptions::fake();
    $order = ($this->submit)($this->a);
    $other = ($this->submit)($this->b);
    // Seules les tâches de notification sont retenues : les diffusions passent
    // par la file synchrone, comme si Reverb était arrêté pendant la requête.
    Queue::fake([CallQueuedListener::class]);
    $recorder = ($this->recorder)(['OrderValidated', 'OrdersProcessed', 'OrderShippingUpdated']);
    $recorder->sent = [];

    $validated = validateOrder($order);
    $rejected = ($this->reject)($other);
    $moved = ($this->move)(OrderStatus::InPreparation, [$order]);
    $shipping = $this->advOrders->updateShipping($this->adv, $order->fresh(), 3, null, 'BL-9', true);

    expect($validated->status)->toBe(OrderStatus::Validated)
        ->and($rejected->status)->toBe(OrderStatus::Rejected)
        ->and($moved->applied)->toHaveCount(1)
        ->and($shipping->delivery_note_ref)->toBe('BL-9')
        ->and($order->fresh()->status)->toBe(OrderStatus::InPreparation);
    // Région et ADV pour la validation, région et ADV pour le traitement, ADV pour la saisie.
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Reverb injoignable (OrderValidated)');
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Reverb injoignable (OrdersProcessed)');
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Reverb injoignable (OrderShippingUpdated)');
    Exceptions::assertReportedCount(5);
    // Une diffusion en échec n'empêche pas les suivantes.
    expect(collect($recorder->sent)->pluck('event')->all())->toBe(['OrderStatusChanged', 'OrderRejected', 'OrderStatusChanged', 'OrderStatusChanged']);
});

it('ignores an event whose order or day no longer exists', function () {
    Event::fake($this->realtime);
    $dayId = $this->businessDay->id;

    $relay = app(BroadcastOrderActivity::class);
    $relay->handleOrderValidated(new OrderValidated(999_999, $this->a->id, $this->centre->id, $dayId, 2, null, 0));
    $relay->handleOrderRejected(new OrderRejected(999_999, $this->a->id, $this->centre->id, $dayId, 2, null));
    $relay->handleOrderCancelledByAdv(new OrderCancelledByAdv(999_999, $this->a->id, $this->centre->id, $dayId, 3, null));
    $relay->handleOrderShippingUpdated(new OrderShippingUpdated(999_999, $dayId, 4, null));
    $relay->handleOrdersProcessed(new OrdersProcessed([], OrderStatus::Shipped, null, 'BATCH'));
    $relay->handleOrdersProcessed(new OrdersProcessed([
        ['orderId' => 999_999, 'clientId' => $this->a->id, 'regionId' => $this->centre->id, 'businessDayId' => $dayId, 'version' => 3],
    ], OrderStatus::Shipped, null, 'BATCH'));

    foreach ($this->realtime as $class) {
        Event::assertNotDispatched($class);
    }
});
