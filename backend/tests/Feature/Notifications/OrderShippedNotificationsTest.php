<?php

use App\Domain\Access\Role;
use App\Domain\Orders\AdvOrders;
use App\Domain\Orders\ClientOrders;
use App\Domain\Orders\OrderStatus;
use App\Events\OrdersProcessed;
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
use App\Listeners\NotifyOrderShipped;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// N-09 : commande expédiée, notifiée aux comptes du client (§7.8, R13,
// EF-NOT-06 ; contrat du lot 6, §7) : une notification par commande et
// destinataire, à l'expédition seulement, en changement unitaire ou en masse.

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:30 à Alger : commande jusqu'à 13:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    // Temps réel simulé : les tâches de notification, elles, s'exécutent (file synchrone).
    Event::fake([
        RealtimeOrderSubmitted::class, RealtimeOrderUpdated::class, RealtimeOrderCancelled::class, OrderStatusChanged::class, RealtimeOrdersExpired::class,
        RealtimeOrderValidated::class, RealtimeOrderRejected::class, RealtimeOrderCancelledByAdv::class, RealtimeOrdersProcessed::class,
        AdvOrderValidated::class, AdvOrderCancelledByAdv::class, AdvOrderShippingUpdated::class,
    ]);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->a = Client::factory()->create(['code' => 'C0012', 'region_id' => $this->centre->id]);
    $this->b = Client::factory()->create(['code' => 'C0047', 'region_id' => $this->centre->id]);
    $this->c = Client::factory()->create(['code' => 'C0050', 'region_id' => $this->centre->id]);
    // Deux comptes pour A, un pour B et C ; jamais destinataires : compte désactivé, ancien compte de A.
    $this->accountA = userWithRole(Role::Client, ['client_id' => $this->a->id]);
    $this->secondA = userWithRole(Role::Client, ['client_id' => $this->a->id]);
    $this->accountB = userWithRole(Role::Client, ['client_id' => $this->b->id]);
    $this->accountC = userWithRole(Role::Client, ['client_id' => $this->c->id]);
    $this->inactiveA = userWithRole(Role::Client, ['client_id' => $this->a->id, 'is_active' => false]);
    $this->formerA = userWithRole(Role::Adv, ['client_id' => $this->a->id]);
    $this->orange = Product::factory()->create(['sku' => 'P0042']);
    $this->regionDay = openOrderingDay('2026-10-08', $this->centre, [[$this->orange, 500]]);
    $this->businessDay = $this->regionDay->businessDay;
    $this->adv = userWithRole(Role::Adv);

    $this->accounts = [$this->a->id => $this->accountA, $this->b->id => $this->accountB, $this->c->id => $this->accountC];
    $this->advOrders = app(AdvOrders::class);
    // Commande envoyée puis validée.
    $this->validated = function (Client $client, int $quantity = 40): Order {
        $order = app(ClientOrders::class)->submit(
            $this->accounts[$client->id],
            (string) Str::uuid(),
            BusinessClock::parseDay('2026-10-08'),
            [['product_id' => $this->orange->id, 'quantity' => $quantity]],
            null,
        )['order'];

        return validateOrder($order);
    };
    // Changement de statut de l'ADV, au statut courant de chaque commande.
    $this->move = fn (OrderStatus $to, array $orders) => $this->advOrders->transition(
        $this->adv,
        $to,
        array_map(fn (Order $order) => ['id' => $order->public_id, 'version' => $order->fresh()->version], $orders),
    );
    $this->notificationsOf = fn (User $user) => DatabaseNotification::query()
        ->whereMorphedTo('notifiable', $user)
        ->where('type', 'ORDER_SHIPPED')
        ->orderBy('created_at')
        ->orderBy('id')
        ->get();
    $this->countOf = fn () => DatabaseNotification::query()->where('type', 'ORDER_SHIPPED')->count();
    // Type et données enregistrées, clés triées : MySQL réordonne les clés JSON.
    $this->contentOf = fn (DatabaseNotification $notification) => [$notification->type, collect($notification->data)->sortKeys()->all()];
    $this->expected = fn (Order $order) => ['ORDER_SHIPPED', collect([
        'type' => 'ORDER_SHIPPED',
        'code' => 'N-09',
        'title' => "Commande {$order->number} expédiée",
        'body' => "Votre commande {$order->number} a été expédiée.",
        'url' => "/client/commandes/{$order->public_id}",
    ])->sortKeys()->all()];
});

it('sends N-09 to the active client accounts of each order shipped, in one bulk change', function () {
    $orderA = ($this->validated)($this->a);
    $orderB = ($this->validated)($this->b);
    $orderC = ($this->validated)($this->c);
    ($this->move)(OrderStatus::InPreparation, [$orderA, $orderB, $orderC]);
    ($this->move)(OrderStatus::Prepared, [$orderA, $orderB, $orderC]);
    // Ni la préparation ni la préparation terminée ne préviennent le client.
    expect(($this->countOf)())->toBe(0);

    // C est resté en arrière (version périmée) : refusée, donc pas expédiée.
    $this->travel(1)->minutes();
    $result = $this->advOrders->transition($this->adv, OrderStatus::Shipped, [
        ['id' => $orderA->public_id, 'version' => $orderA->fresh()->version],
        ['id' => $orderB->public_id, 'version' => $orderB->fresh()->version],
        ['id' => $orderC->public_id, 'version' => 1],
    ]);

    expect($result->toArray()['refused'])->toHaveCount(1);
    foreach ([$this->accountA, $this->secondA] as $recipient) {
        expect(($this->contentOf)(($this->notificationsOf)($recipient)->sole()))->toBe(($this->expected)($orderA));
    }
    expect(($this->contentOf)(($this->notificationsOf)($this->accountB)->sole()))->toBe(($this->expected)($orderB));
    foreach ([$this->accountC, $this->inactiveA, $this->formerA] as $nobody) {
        expect(($this->notificationsOf)($nobody))->toBeEmpty();
    }
    expect(($this->countOf)())->toBe(3);

    // Dans le centre de notifications du client, au format du contrat.
    $this->actingAs($this->accountB)
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.0', [
            'id' => ($this->notificationsOf)($this->accountB)->sole()->id,
            'type' => 'ORDER_SHIPPED',
            'code' => 'N-09',
            'title' => "Commande {$orderB->number} expédiée",
            'body' => "Votre commande {$orderB->number} a été expédiée.",
            'url' => "/client/commandes/{$orderB->public_id}",
            'read_at' => null,
            'created_at' => '2026-10-08T08:31:00+00:00',
        ]);

    // Plus tard, la dernière commande rejoint les autres : elle seule est notifiée.
    ($this->move)(OrderStatus::Shipped, [$orderC]);
    expect(($this->notificationsOf)($this->accountC))->toHaveCount(1)
        ->and(($this->countOf)())->toBe(4);
});

it('sends N-09 once per order and recipient when the event, the job or the order is replayed (§9.7)', function () {
    $order = ($this->validated)($this->a);
    ($this->move)(OrderStatus::InPreparation, [$order]);
    ($this->move)(OrderStatus::Prepared, [$order]);
    ($this->move)(OrderStatus::Shipped, [$order]);
    $event = new OrdersProcessed(
        [['orderId' => $order->id, 'clientId' => $this->a->id, 'regionId' => $this->centre->id, 'businessDayId' => $this->businessDay->id, 'version' => 5]],
        OrderStatus::Shipped,
        $this->adv->id,
        strtoupper((string) Str::ulid()),
    );

    // Événement rediffusé, tâche relancée, nouvelle demande d'expédition de la même commande.
    event($event);
    app(NotifyOrderShipped::class)->handle($event);
    $replay = ($this->move)(OrderStatus::Shipped, [$order]);

    expect($replay->toArray()['refused'])->toHaveCount(1)
        ->and(($this->notificationsOf)($this->accountA))->toHaveCount(1)
        ->and(($this->notificationsOf)($this->secondA))->toHaveCount(1)
        ->and(($this->countOf)())->toBe(2);
});

it('sends N-09 from a background job, only when the order reaches « Expédiée » (EF-NOT-06)', function () {
    $order = ($this->validated)($this->a);
    $other = ($this->validated)($this->b);
    Queue::fake();

    ($this->move)(OrderStatus::InPreparation, [$order, $other]);
    ($this->move)(OrderStatus::Prepared, [$order, $other]);
    // Aucune tâche tant que la commande n'est pas expédiée.
    Queue::assertNotPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === NotifyOrderShipped::class);

    ($this->move)(OrderStatus::Shipped, [$order, $other]);

    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === NotifyOrderShipped::class
        && $job->data[0] instanceof OrdersProcessed
        && $job->data[0]->to === OrderStatus::Shipped
        && array_column($job->data[0]->orders, 'orderId') === [$order->id, $other->id]
        // Relancée après un échec technique, sans double envoi.
        && $job->tries === 3
        && $job->backoff === [10, 60]);
    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === NotifyOrderShipped::class, 1);
    // Rien n'est envoyé pendant l'expédition.
    expect(($this->countOf)())->toBe(0);
});

it('ignores a status other than « Expédiée », an order not shipped and an order that no longer exists', function () {
    $validated = ($this->validated)($this->a);
    $item = fn (Order $order, int $version) => ['orderId' => $order->id, 'clientId' => $order->client_id, 'regionId' => $order->region_id, 'businessDayId' => $order->business_day_id, 'version' => $version];

    // Statut atteint autre que l'expédition (même si la commande a, depuis, été expédiée).
    ($this->move)(OrderStatus::InPreparation, [$validated]);
    ($this->move)(OrderStatus::Prepared, [$validated]);
    ($this->move)(OrderStatus::Shipped, [$validated]);
    $before = ($this->countOf)();
    app(NotifyOrderShipped::class)->handle(new OrdersProcessed([$item($validated, 3)], OrderStatus::Prepared, $this->adv->id, 'BATCH'));
    // Commande qui n'est pas expédiée, commande inconnue, liste vide.
    $notShipped = ($this->validated)($this->b);
    app(NotifyOrderShipped::class)->handle(new OrdersProcessed([$item($notShipped, 3), ['orderId' => 999_999, 'clientId' => 1, 'regionId' => 1, 'businessDayId' => 1, 'version' => 2]], OrderStatus::Shipped, $this->adv->id, 'BATCH'));
    app(NotifyOrderShipped::class)->handle(new OrdersProcessed([], OrderStatus::Shipped, $this->adv->id, 'BATCH'));

    expect($before)->toBe(2)
        ->and(($this->countOf)())->toBe(2)
        ->and(($this->notificationsOf)($this->accountB))->toBeEmpty();
});

it('notifies 120 orders shipped in one change by chunks, without one query per order beyond the notification itself (EF-NOT-06)', function () {
    $items = [];
    foreach (Client::factory()->count(120)->create(['region_id' => $this->centre->id]) as $client) {
        userWithRole(Role::Client, ['client_id' => $client->id]);
        $order = Order::factory()->shipped()->create([
            'business_day_id' => $this->businessDay->id,
            'region_id' => $this->centre->id,
            'client_id' => $client->id,
            'version' => 5,
        ]);
        $items[] = ['orderId' => $order->id, 'clientId' => $client->id, 'regionId' => $this->centre->id, 'businessDayId' => $this->businessDay->id, 'version' => 5];
    }
    // Diffusions laissées en file : seule la tâche de notification est mesurée.
    Queue::fake();

    DB::enableQueryLog();
    app(NotifyOrderShipped::class)->handle(new OrdersProcessed($items, OrderStatus::Shipped, $this->adv->id, 'BATCH'));
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Par destinataire : contrôle de la clé d'unicité et enregistrement ; par
    // paquet de 100 commandes : les commandes, les comptes et leurs rôles.
    expect(($this->countOf)())->toBe(120)
        ->and($queries)->toBeLessThanOrEqual(2 * 120 + 2 * 2);
});
