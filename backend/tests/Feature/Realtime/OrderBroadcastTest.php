<?php

use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Domain\Orders\ClientOrders;
use App\Events\OrderCancelled;
use App\Events\OrdersExpired;
use App\Events\OrderSubmitted;
use App\Events\OrderUpdated;
use App\Events\Realtime\OrderCancelled as RealtimeOrderCancelled;
use App\Events\Realtime\OrdersExpired as RealtimeOrdersExpired;
use App\Events\Realtime\OrderStatusChanged;
use App\Events\Realtime\OrderSubmitted as RealtimeOrderSubmitted;
use App\Events\Realtime\OrderUpdated as RealtimeOrderUpdated;
use App\Listeners\BroadcastOrderActivity;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Diffusion temps réel des commandes (§9.6 ; contrat du lot 5, §5) :
// OrderSubmitted, OrderUpdated et OrderCancelled sur region-day.{regionId}.{day}
// (avec le client) et sur client.{clientId} ; à la clôture, OrdersExpired
// { day, count } une fois par région et OrderStatusChanged sur le canal de
// chaque client ; N-05 et N-06 sur user.{id}. Identifiants seulement, aucune
// quantité (EF-NOT-03).

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:30 à Alger : commande jusqu'à 13:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    $this->regional = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['region_id' => $this->centre->id, 'user_id' => $this->regional->id]);
    $this->a = Client::factory()->create(['code' => 'C0012', 'name' => 'Superette El Baraka', 'region_id' => $this->centre->id]);
    $this->b = Client::factory()->create(['code' => 'C0047', 'region_id' => $this->centre->id]);
    $this->e = Client::factory()->create(['code' => 'C0090', 'region_id' => $this->est->id]);
    $this->accountA = userWithRole(Role::Client, ['client_id' => $this->a->id]);
    $this->accountB = userWithRole(Role::Client, ['client_id' => $this->b->id]);
    $this->accountE = userWithRole(Role::Client, ['client_id' => $this->e->id]);
    $this->orange = Product::factory()->create(['sku' => 'P0042']);
    $this->regionDay = openOrderingDay('2026-10-08', $this->centre, [[$this->orange, 500]]);
    openOrderingDay('2026-10-08', $this->est, [[$this->orange, 300]]);

    $this->orders = app(ClientOrders::class);
    $this->submit = fn (User $by, int $quantity = 10, ?string $requestId = null) => $this->orders->submit(
        $by,
        $requestId ?? (string) Str::uuid(),
        BusinessClock::parseDay('2026-10-08'),
        [['product_id' => $this->orange->id, 'quantity' => $quantity]],
        null,
    )['order'];
    $this->modify = fn (Order $order, int $version, int $quantity) => $this->orders->modify(
        $this->accountA, $order->public_id, $version, [['product_id' => $this->orange->id, 'quantity' => $quantity]], null,
    );
    $this->close = fn () => app(DayCalendar::class)->close($this->regionDay->businessDay->fresh());
    $this->realtime = [RealtimeOrderSubmitted::class, RealtimeOrderUpdated::class, RealtimeOrderCancelled::class, OrderStatusChanged::class, RealtimeOrdersExpired::class];
    $this->channels = fn (ShouldBroadcast $event) => array_map(fn ($channel) => $channel->name, $event->broadcastOn());
    $this->dispatched = fn (string $class) => Event::dispatched($class)->map(fn (array $call) => $call[0])->values();
    $this->regionChannel = fn (Region $region) => "private-region-day.{$region->id}.2026-10-08";
    $this->clientChannel = fn (Client $client) => "private-client.{$client->id}";
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
});

it('broadcasts OrderSubmitted, OrderUpdated and OrderCancelled to the region day, with the client, and to the client', function () {
    Event::fake($this->realtime);

    $requestId = (string) Str::uuid();
    $order = ($this->submit)($this->accountA, 40, $requestId);
    // Renvoi du même envoi (RG-C07) : rien de plus.
    ($this->submit)($this->accountA, 40, $requestId);
    ($this->modify)($order, 1, 30);
    // Rien de changé (I-51) : rien n'est diffusé.
    ($this->modify)($order, 2, 30);
    $this->orders->cancel($this->accountA, $order->public_id, 2);

    $identity = ['id' => $order->public_id, 'number' => 'RMK-261008-00001'];
    foreach ([
        [RealtimeOrderSubmitted::class, 'OrderSubmitted', 'SUBMITTED', 1],
        [RealtimeOrderUpdated::class, 'OrderUpdated', 'SUBMITTED', 2],
        [RealtimeOrderCancelled::class, 'OrderCancelled', 'CANCELLED', 3],
    ] as [$class, $name, $status, $version]) {
        $events = ($this->dispatched)($class);
        expect($events)->toHaveCount(2)
            ->and(($this->channels)($events[0]))->toBe([($this->regionChannel)($this->centre)])
            ->and($events[0]->broadcastAs())->toBe($name)
            ->and($events[0]->broadcastWith())->toBe([...$identity, 'status' => $status, 'version' => $version, 'day' => '2026-10-08', 'client_id' => $this->a->id])
            ->and(($this->channels)($events[1]))->toBe([($this->clientChannel)($this->a)])
            ->and($events[1]->broadcastAs())->toBe($name)
            ->and($events[1]->broadcastWith())->toBe([...$identity, 'status' => $status, 'version' => $version, 'day' => '2026-10-08']);
    }
    Event::assertNotDispatched(OrderStatusChanged::class);
    Event::assertNotDispatched(RealtimeOrdersExpired::class);
});

it('broadcasts OrdersExpired once per region and OrderStatusChanged to each client when the day closes (§4.4)', function () {
    Event::fake($this->realtime);
    $orderA = ($this->submit)($this->accountA);
    $orderB = ($this->submit)($this->accountB);
    $orderE = ($this->submit)($this->accountE);
    // Annulée avant la clôture : n'expire pas.
    $cancelledOwner = Client::factory()->create(['region_id' => $this->centre->id]);
    $cancelled = ($this->submit)(userWithRole(Role::Client, ['client_id' => $cancelledOwner->id]));
    $this->orders->cancel(User::query()->where('client_id', $cancelledOwner->id)->sole(), $cancelled->public_id, 1);

    expect(($this->close)())->toBeTrue();

    $expired = ($this->dispatched)(RealtimeOrdersExpired::class);
    expect($expired)->toHaveCount(2)
        ->and(($this->channels)($expired[0]))->toBe([($this->regionChannel)($this->centre)])
        ->and($expired[0]->broadcastAs())->toBe('OrdersExpired')
        ->and($expired[0]->broadcastWith())->toBe(['day' => '2026-10-08', 'count' => 2])
        ->and(($this->channels)($expired[1]))->toBe([($this->regionChannel)($this->est)])
        ->and($expired[1]->broadcastWith())->toBe(['day' => '2026-10-08', 'count' => 1]);

    $changes = ($this->dispatched)(OrderStatusChanged::class);
    expect($changes)->toHaveCount(3)
        ->and($changes->map(fn (OrderStatusChanged $event) => ($this->channels)($event))->all())->toBe([
            [($this->clientChannel)($this->a)], [($this->clientChannel)($this->b)], [($this->clientChannel)($this->e)],
        ])
        ->and($changes[0]->broadcastAs())->toBe('OrderStatusChanged')
        ->and($changes->map(fn (OrderStatusChanged $event) => $event->broadcastWith())->all())->toBe([
            ['id' => $orderA->public_id, 'number' => $orderA->number, 'status' => 'EXPIRED', 'version' => 2, 'day' => '2026-10-08'],
            ['id' => $orderB->public_id, 'number' => $orderB->number, 'status' => 'EXPIRED', 'version' => 2, 'day' => '2026-10-08'],
            ['id' => $orderE->public_id, 'number' => $orderE->number, 'status' => 'EXPIRED', 'version' => 2, 'day' => '2026-10-08'],
        ]);
});

it('broadcasts from the queue, never during the request', function () {
    foreach ($this->realtime as $class) {
        expect(is_subclass_of($class, ShouldBroadcast::class))->toBeTrue()
            ->and(is_subclass_of($class, ShouldBroadcastNow::class))->toBeFalse();
    }

    Queue::fake();
    $order = ($this->submit)($this->accountA, 40);
    ($this->modify)($order, 1, 30);
    $this->orders->cancel($this->accountA, $order->public_id, 2);
    ($this->submit)($this->accountB);
    ($this->close)();

    foreach ($this->realtime as $class) {
        Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job) => $job->event instanceof $class);
    }
    // Région et client pour chaque action, un par région et un par commande à la clôture.
    Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job) => $job->event instanceof RealtimeOrderSubmitted, 4);
    Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job) => $job->event instanceof RealtimeOrdersExpired, 1);
    Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job) => $job->event instanceof OrderStatusChanged, 1);
});

it('delivers the events and the N-05 and N-06 notifications to the broadcaster with identifiers only', function () {
    $recorder = ($this->recorder)();

    $order = ($this->submit)($this->accountA, 40);
    $this->orders->cancel($this->accountA, $order->public_id, 1);
    ($this->submit)($this->accountB);
    ($this->close)();

    $sent = collect($recorder->sent);
    $identity = ['id' => $order->public_id, 'number' => 'RMK-261008-00001'];
    expect($sent->where('event', 'OrderSubmitted')->values()->take(2)->all())->toBe([
        [
            'channels' => [($this->regionChannel)($this->centre)],
            'event' => 'OrderSubmitted',
            'payload' => [...$identity, 'status' => 'SUBMITTED', 'version' => 1, 'day' => '2026-10-08', 'client_id' => $this->a->id],
        ],
        [
            'channels' => [($this->clientChannel)($this->a)],
            'event' => 'OrderSubmitted',
            'payload' => [...$identity, 'status' => 'SUBMITTED', 'version' => 1, 'day' => '2026-10-08'],
        ],
    ])->and($sent->where('event', 'OrderCancelled')->values()->all())->toBe([
        [
            'channels' => [($this->regionChannel)($this->centre)],
            'event' => 'OrderCancelled',
            'payload' => [...$identity, 'status' => 'CANCELLED', 'version' => 2, 'day' => '2026-10-08', 'client_id' => $this->a->id],
        ],
        [
            'channels' => [($this->clientChannel)($this->a)],
            'event' => 'OrderCancelled',
            'payload' => [...$identity, 'status' => 'CANCELLED', 'version' => 2, 'day' => '2026-10-08'],
        ],
    ])->and($sent->where('event', 'OrdersExpired')->values()->all())->toBe([[
        'channels' => [($this->regionChannel)($this->centre)],
        'event' => 'OrdersExpired',
        'payload' => ['day' => '2026-10-08', 'count' => 1],
    ]])->and($sent->where('event', 'OrderStatusChanged')->values()->all())->toBe([[
        'channels' => [($this->clientChannel)($this->b)],
        'event' => 'OrderStatusChanged',
        'payload' => [
            'id' => Order::query()->where('client_id', $this->b->id)->value('public_id'),
            'number' => 'RMK-261008-00002', 'status' => 'EXPIRED', 'version' => 2, 'day' => '2026-10-08',
        ],
    ]]);

    // N-05 et N-06 sur le canal personnel du régional : le contenu du centre de notifications.
    $notifications = $sent->where('event', 'Illuminate\Notifications\Events\BroadcastNotificationCreated')
        ->filter(fn (array $sent) => $sent['channels'] === ["private-user.{$this->regional->id}"])
        ->values();
    expect($notifications->pluck('payload.type')->all())->toBe(['ORDER_SUBMITTED', 'ORDER_CHANGED', 'ORDER_SUBMITTED'])
        ->and($notifications[0]['payload'])->toBe([
            'type' => 'ORDER_SUBMITTED',
            'code' => 'N-05',
            'title' => 'Nouvelle commande — C0012 Superette El Baraka',
            'body' => 'RMK-261008-00001 à valider.',
            'url' => "/regional/commandes/{$order->public_id}",
            'read_at' => null,
            'created_at' => '2026-10-08T08:30:00+00:00',
            'id' => DatabaseNotification::query()->whereMorphedTo('notifiable', $this->regional)->where('type', 'ORDER_SUBMITTED')
                ->where('data->url', "/regional/commandes/{$order->public_id}")->sole()->id,
        ]);
    // N-14 sur le canal personnel du compte de B.
    expect($sent->where('event', 'Illuminate\Notifications\Events\BroadcastNotificationCreated')
        ->filter(fn (array $sent) => $sent['channels'] === ["private-user.{$this->accountB->id}"])
        ->pluck('payload.type')->all())->toBe(['ORDER_EXPIRED']);
});

it('never makes an order or the closing fail when the realtime relay fails', function () {
    Exceptions::fake();
    // Seules les tâches de notification sont retenues : les diffusions passent
    // par la file synchrone, comme si Reverb était arrêté pendant la requête.
    Queue::fake([CallQueuedListener::class]);
    $recorder = ($this->recorder)(['OrderSubmitted', 'OrdersExpired']);

    $order = ($this->submit)($this->accountA, 40);
    expect(($this->close)())->toBeTrue();

    expect($order->exists)->toBeTrue()
        ->and($order->fresh()->status->value)->toBe('EXPIRED');
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Reverb injoignable (OrderSubmitted)');
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Reverb injoignable (OrdersExpired)');
    // Région et client pour l'envoi, la région à la clôture.
    Exceptions::assertReportedCount(3);
    // Une diffusion en échec n'empêche pas les suivantes (dont la clôture, lot 3).
    expect(collect($recorder->sent)->pluck('event')->all())->toBe(['OrderStatusChanged', 'DayClosed']);
});

it('ignores an event whose order or day no longer exists', function () {
    Event::fake($this->realtime);
    $dayId = $this->regionDay->business_day_id;

    $relay = app(BroadcastOrderActivity::class);
    $relay->handleOrderSubmitted(new OrderSubmitted(999_999, $this->a->id, $this->centre->id, $dayId, 1, null));
    $relay->handleOrderUpdated(new OrderUpdated(999_999, $this->a->id, $this->centre->id, $dayId, 2, null));
    $relay->handleOrderCancelled(new OrderCancelled(999_999, $this->a->id, $this->centre->id, $dayId, 2, null));
    $relay->handleOrdersExpired(new OrdersExpired($dayId, [999_999]));
    $relay->handleOrdersExpired(new OrdersExpired(999_999, [1]));

    foreach ($this->realtime as $class) {
        Event::assertNotDispatched($class);
    }
});
