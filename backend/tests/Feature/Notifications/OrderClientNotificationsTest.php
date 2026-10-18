<?php

use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Domain\Orders\ClientOrders;
use App\Domain\Orders\OrderStatus;
use App\Events\OrdersExpired;
use App\Events\Realtime\OrderCancelled as RealtimeOrderCancelled;
use App\Events\Realtime\OrdersExpired as RealtimeOrdersExpired;
use App\Events\Realtime\OrderStatusChanged;
use App\Events\Realtime\OrderSubmitted as RealtimeOrderSubmitted;
use App\Events\Realtime\OrderUpdated as RealtimeOrderUpdated;
use App\Listeners\NotifyOrdersExpired;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use App\Notifications\OrderDecidedNotification;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Notifications N-07 et N-14 aux comptes du client de la commande (§7.8,
// §4.4, I-39, I-41, I-53, EF-NOT-06 ; contrat du lot 5, §5).

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:30 à Alger : commande jusqu'à 13:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    // Temps réel simulé : les tâches de notification, elles, s'exécutent (file synchrone).
    Event::fake([RealtimeOrderSubmitted::class, RealtimeOrderUpdated::class, RealtimeOrderCancelled::class, OrderStatusChanged::class, RealtimeOrdersExpired::class]);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->a = Client::factory()->create(['code' => 'C0012', 'name' => 'Superette El Baraka', 'region_id' => $this->centre->id]);
    $this->b = Client::factory()->create(['code' => 'C0047', 'region_id' => $this->centre->id]);
    $this->c = Client::factory()->create(['code' => 'C0050', 'region_id' => $this->centre->id]);
    // Comptes clients : deux pour A (un client peut avoir plusieurs comptes), un pour B et C.
    $this->accountA = userWithRole(Role::Client, ['client_id' => $this->a->id]);
    $this->secondA = userWithRole(Role::Client, ['client_id' => $this->a->id]);
    $this->accountB = userWithRole(Role::Client, ['client_id' => $this->b->id]);
    $this->accountC = userWithRole(Role::Client, ['client_id' => $this->c->id]);
    // Jamais destinataires : compte désactivé, compte rattaché à A passé à un autre rôle.
    $this->inactiveA = userWithRole(Role::Client, ['client_id' => $this->a->id, 'is_active' => false]);
    $this->formerA = userWithRole(Role::Adv, ['client_id' => $this->a->id]);
    $this->orange = Product::factory()->create(['sku' => 'P0042']);
    $this->regionDay = openOrderingDay('2026-10-08', $this->centre, [[$this->orange, 500]]);
    $this->businessDay = $this->regionDay->businessDay;

    $this->orders = app(ClientOrders::class);
    $this->submit = fn (User $by, int $quantity = 10) => $this->orders->submit(
        $by,
        (string) Str::uuid(),
        BusinessClock::parseDay('2026-10-08'),
        [['product_id' => $this->orange->id, 'quantity' => $quantity]],
        null,
    )['order'];
    $this->close = function (): bool {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 20:00', 'Africa/Algiers'));

        return app(DayCalendar::class)->close($this->businessDay->fresh());
    };
    $this->notificationsOf = fn (User $user, string $type) => DatabaseNotification::query()
        ->whereMorphedTo('notifiable', $user)
        ->where('type', $type)
        ->orderBy('created_at')
        ->get();
    $this->countOf = fn (string $type) => DatabaseNotification::query()->where('type', $type)->count();
    // Type et données enregistrées, clés triées : MySQL réordonne les clés JSON.
    $this->contentOf = fn (DatabaseNotification $notification) => [$notification->type, collect($notification->data)->sortKeys()->all()];
    $this->content = fn (string $type, array $data) => [$type, collect($data)->sortKeys()->all()];
    // Commande décidée (lot 6) : [quantité demandée, quantité validée] par ligne.
    $this->decided = function (OrderStatus $status, int $version, array $lines = []): Order {
        $order = Order::factory()->status($status)->create([
            'business_day_id' => $this->businessDay->id,
            'region_id' => $this->centre->id,
            'client_id' => $this->a->id,
            'version' => $version,
        ]);
        foreach ($lines as [$requested, $validated]) {
            OrderLine::factory()->for($order)->validatedQty($validated)->create(['requested_qty' => $requested]);
        }

        return $order;
    };
});

it('sends N-14 to the active client accounts of each order expired at the closing of the day (§4.4, I-41)', function () {
    $orderA = ($this->submit)($this->accountA);
    // Annulée avant la clôture : n'expire pas.
    $orderB = ($this->submit)($this->accountB);
    $this->orders->cancel($this->accountB, $orderB->public_id, 1);
    // Client bloqué après l'envoi : sa commande expire, ses comptes sont prévenus (I-53).
    $orderC = ($this->submit)($this->accountC);
    $this->c->forceFill(['status' => 'BLOCKED'])->save();

    expect(($this->close)())->toBeTrue();

    $expected = fn (Order $order, string $number) => ($this->content)('ORDER_EXPIRED', [
        'type' => 'ORDER_EXPIRED',
        'code' => 'N-14',
        'title' => "Commande {$number} expirée",
        'body' => 'Elle n\'a pas été validée avant la clôture de la journée.',
        'url' => "/client/commandes/{$order->public_id}",
    ]);
    foreach ([$this->accountA, $this->secondA] as $recipient) {
        $notifications = ($this->notificationsOf)($recipient, 'ORDER_EXPIRED');
        expect($notifications)->toHaveCount(1)
            ->and(($this->contentOf)($notifications[0]))->toBe($expected($orderA, 'RMK-261008-00001'))
            ->and($notifications[0]->read_at)->toBeNull();
    }
    expect(($this->contentOf)(($this->notificationsOf)($this->accountC, 'ORDER_EXPIRED')->sole()))->toBe($expected($orderC, 'RMK-261008-00003'));
    foreach ([$this->accountB, $this->inactiveA, $this->formerA] as $nobody) {
        expect(($this->notificationsOf)($nobody, 'ORDER_EXPIRED'))->toBeEmpty();
    }
    expect(($this->countOf)('ORDER_EXPIRED'))->toBe(3);

    // Dans le centre de notifications du client, au format du contrat.
    $this->actingAs($this->secondA)
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.0', [
            'id' => ($this->notificationsOf)($this->secondA, 'ORDER_EXPIRED')->sole()->id,
            'type' => 'ORDER_EXPIRED',
            'code' => 'N-14',
            'title' => 'Commande RMK-261008-00001 expirée',
            'body' => 'Elle n\'a pas été validée avant la clôture de la journée.',
            'url' => "/client/commandes/{$orderA->public_id}",
            'read_at' => null,
            'created_at' => '2026-10-08T19:00:00+00:00',
        ]);
});

it('never sends N-14 twice when the closing, the event or the job is replayed (§9.7)', function () {
    $order = ($this->submit)($this->accountA);
    ($this->close)();
    $event = new OrdersExpired($this->businessDay->id, [$order->id]);

    // Clôture relancée (sans effet), événement rediffusé, tâche relancée.
    expect(($this->close)())->toBeFalse();
    event($event);
    app(NotifyOrdersExpired::class)->handle($event);

    expect(($this->notificationsOf)($this->accountA, 'ORDER_EXPIRED'))->toHaveCount(1)
        ->and(($this->notificationsOf)($this->secondA, 'ORDER_EXPIRED'))->toHaveCount(1)
        ->and(($this->countOf)('ORDER_EXPIRED'))->toBe(2);
});

it('sends N-14 from a background job, after the closing (EF-NOT-06)', function () {
    $orderA = ($this->submit)($this->accountA);
    $orderB = ($this->submit)($this->accountB);
    Queue::fake();

    ($this->close)();

    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === NotifyOrdersExpired::class
        && $job->data[0] instanceof OrdersExpired
        && $job->data[0]->businessDayId === $this->businessDay->id
        && $job->data[0]->orderIds === [$orderA->id, $orderB->id]
        // Relancée après un échec technique, sans double envoi.
        && $job->tries === 3
        && $job->backoff === [10, 60]);
    // Rien n'est envoyé pendant la clôture.
    expect(($this->countOf)('ORDER_EXPIRED'))->toBe(0);
});

it('notifies the 150 expired orders of a day without one query per order beyond the notification itself (EF-NOT-06)', function () {
    $clients = Client::factory()->count(150)->create(['region_id' => $this->centre->id]);
    $ids = [];
    foreach ($clients as $client) {
        userWithRole(Role::Client, ['client_id' => $client->id]);
        $ids[] = Order::factory()->expired()->create([
            'business_day_id' => $this->businessDay->id,
            'region_id' => $this->centre->id,
            'client_id' => $client->id,
            'version' => 2,
        ])->id;
    }
    // Diffusions laissées en file : seule la tâche de notification est mesurée.
    Queue::fake();

    DB::enableQueryLog();
    app(NotifyOrdersExpired::class)->handle(new OrdersExpired($this->businessDay->id, $ids));
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Par destinataire : contrôle de la clé d'unicité et enregistrement ; par
    // paquet de 100 commandes : les commandes, les comptes et leurs rôles.
    expect(($this->countOf)('ORDER_EXPIRED'))->toBe(150)
        ->and($queries)->toBeLessThanOrEqual(2 * 150 + 6);
});

it('ignores an order that is not expired or no longer exists', function () {
    $order = ($this->submit)($this->accountA);

    app(NotifyOrdersExpired::class)->handle(new OrdersExpired($this->businessDay->id, [$order->id, 999_999]));
    app(NotifyOrdersExpired::class)->handle(new OrdersExpired($this->businessDay->id, []));

    expect(($this->countOf)('ORDER_EXPIRED'))->toBe(0);
});

it('builds the four N-07 variants: validated, validated with adjusted lines, rejected, cancelled (I-39)', function () {
    $send = function (Order $order): array {
        $notification = OrderDecidedNotification::fromOrder($order);
        expect($notification->sendOnce($notification->recipients()))->toBe(2);
        $sent = DatabaseNotification::query()->whereMorphedTo('notifiable', $this->accountA)->where('type', 'ORDER_DECIDED')->get()
            ->firstWhere('data.url', "/client/commandes/{$order->public_id}");

        return ($this->contentOf)($sent);
    };
    $expected = fn (Order $order, string $title, string $body) => ($this->content)('ORDER_DECIDED', [
        'type' => 'ORDER_DECIDED',
        'code' => 'N-07',
        'title' => $title,
        'body' => $body,
        'url' => "/client/commandes/{$order->public_id}",
    ]);

    // Toutes les lignes validées à la quantité demandée ; puis deux lignes ajustées sur trois.
    $validated = ($this->decided)(OrderStatus::Validated, 2, [[40, 40], [10, 10]]);
    $adjusted = ($this->decided)(OrderStatus::Validated, 2, [[40, 30], [10, 0], [5, 5]]);
    $rejected = ($this->decided)(OrderStatus::Rejected, 2);
    $cancelled = ($this->decided)(OrderStatus::Cancelled, 3, [[40, 40]]);

    expect($send($validated))->toBe($expected($validated, "Commande {$validated->number} validée", "Votre commande {$validated->number} est validée."))
        ->and($send($adjusted))->toBe($expected($adjusted, "Commande {$adjusted->number} validée", "Votre commande {$adjusted->number} est validée (2 lignes ajustées)."))
        ->and($send($rejected))->toBe($expected($rejected, "Commande {$rejected->number} rejetée", "Votre commande {$rejected->number} est rejetée."))
        ->and($send($cancelled))->toBe($expected($cancelled, "Commande {$cancelled->number} annulée", "Votre commande {$cancelled->number} est annulée."));

    // Une seule ligne ajustée : au singulier.
    $one = new OrderDecidedNotification($adjusted, OrderStatus::Validated, 2, 1);
    expect($one->body())->toBe("Votre commande {$adjusted->number} est validée (1 ligne ajustée).");
});

it('sends N-07 to the active client accounts of the client only, once per version (§9.7)', function () {
    $order = ($this->decided)(OrderStatus::Validated, 2, [[40, 40]]);
    $notification = OrderDecidedNotification::fromOrder($order);

    expect($notification->recipients()->pluck('id')->all())->toBe([$this->accountA->id, $this->secondA->id])
        ->and($notification->sendOnce($notification->recipients()))->toBe(2)
        // Tâche relancée : rien de plus.
        ->and(OrderDecidedNotification::fromOrder($order)->sendOnce($notification->recipients()))->toBe(0);

    // Annulation exceptionnelle après la validation (RG-V07) : version suivante, notifiée.
    $this->travel(1)->minutes();
    $order->forceFill(['status' => OrderStatus::Cancelled, 'version' => 3])->save();
    $cancelled = OrderDecidedNotification::fromOrder($order);
    expect($cancelled->sendOnce($cancelled->recipients()))->toBe(2)
        ->and(($this->notificationsOf)($this->accountA, 'ORDER_DECIDED')->pluck('data.body')->all())->toBe([
            "Votre commande {$order->number} est validée.",
            "Votre commande {$order->number} est annulée.",
        ])
        ->and(($this->countOf)('ORDER_DECIDED'))->toBe(4);
    foreach ([$this->inactiveA, $this->formerA, $this->accountB, $this->accountC] as $nobody) {
        expect(($this->notificationsOf)($nobody, 'ORDER_DECIDED'))->toBeEmpty();
    }
});

it('refuses to build N-07 for a status that is not a decision', function () {
    $submitted = ($this->submit)($this->accountA);

    expect(fn () => new OrderDecidedNotification($submitted, OrderStatus::Submitted, 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => OrderDecidedNotification::fromOrder(($this->decided)(OrderStatus::Expired, 2)))->toThrow(InvalidArgumentException::class);
});
