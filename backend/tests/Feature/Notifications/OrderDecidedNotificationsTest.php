<?php

use App\Domain\Access\Role;
use App\Domain\Orders\AdvOrders;
use App\Domain\Orders\ClientOrders;
use App\Domain\Orders\OrderDecisions;
use App\Domain\Orders\OrderStatus;
use App\Events\OrderCancelledByAdv;
use App\Events\OrderRejected;
use App\Events\OrderValidated;
use App\Events\Realtime\AdvOrderCancelledByAdv;
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
use App\Listeners\NotifyOrderDecided;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// N-07 : décision sur la commande, notifiée aux comptes du client (§7.8,
// I-39, I-53, EF-NOT-06 ; contrat du lot 6, §7) : validée, validée avec
// lignes ajustées, rejetée, annulée par l'ADV. Une notification par commande,
// version et destinataire ; pas de N-06 à l'annulation par l'ADV.

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:30 à Alger : commande jusqu'à 13:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    // Temps réel simulé : les tâches de notification, elles, s'exécutent (file synchrone).
    Event::fake([
        RealtimeOrderSubmitted::class, RealtimeOrderUpdated::class, RealtimeOrderCancelled::class, OrderStatusChanged::class, RealtimeOrdersExpired::class,
        RealtimeOrderValidated::class, RealtimeOrderRejected::class, RealtimeOrderCancelledByAdv::class, RealtimeOrdersProcessed::class,
        AdvOrderValidated::class, AdvOrderCancelledByAdv::class,
    ]);
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
    $this->pomme = Product::factory()->create(['sku' => 'P0057']);
    $this->regionDay = openOrderingDay('2026-10-08', $this->centre, [[$this->orange, 500], [$this->pomme, 300]]);
    $this->businessDay = $this->regionDay->businessDay;
    $this->regional = RegionAssignment::query()->where('region_id', $this->centre->id)->sole()->user;
    $this->adv = userWithRole(Role::Adv);

    $this->orders = app(ClientOrders::class);
    $this->accounts = [$this->a->id => $this->accountA, $this->b->id => $this->accountB, $this->c->id => $this->accountC];
    $this->submit = fn (Client $client, array $lines = [[40, 'orange']]) => $this->orders->submit(
        $this->accounts[$client->id],
        (string) Str::uuid(),
        BusinessClock::parseDay('2026-10-08'),
        array_map(fn (array $line) => ['product_id' => $line[1] === 'orange' ? $this->orange->id : $this->pomme->id, 'quantity' => $line[0]], $lines),
        null,
    )['order'];
    $this->reject = fn (Order $order) => app(OrderDecisions::class)->reject($this->regional, $order, $order->fresh()->version, 'QUOTA_EPUISE', null);
    $this->cancelByAdv = fn (Order $order) => app(AdvOrders::class)->cancel($this->adv, $order, $order->fresh()->version, 'RUPTURE', null);
    $this->notificationsOf = fn (User $user, string $type = 'ORDER_DECIDED') => DatabaseNotification::query()
        ->whereMorphedTo('notifiable', $user)
        ->where('type', $type)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get();
    $this->countOf = fn (string $type = 'ORDER_DECIDED') => DatabaseNotification::query()->where('type', $type)->count();
    // Type et données enregistrées, clés triées : MySQL réordonne les clés JSON.
    $this->contentOf = fn (DatabaseNotification $notification) => [$notification->type, collect($notification->data)->sortKeys()->all()];
    $this->expected = fn (Order $order, string $title, string $body) => ['ORDER_DECIDED', collect([
        'type' => 'ORDER_DECIDED',
        'code' => 'N-07',
        'title' => $title,
        'body' => $body,
        'url' => "/client/commandes/{$order->public_id}",
    ])->sortKeys()->all()];
});

it('sends N-07 « validée » to every active client account of the client, once the order is validated', function () {
    $order = ($this->submit)($this->a);

    validateOrder($order);

    foreach ([$this->accountA, $this->secondA] as $recipient) {
        $notifications = ($this->notificationsOf)($recipient);
        expect($notifications)->toHaveCount(1)
            ->and(($this->contentOf)($notifications[0]))->toBe(($this->expected)($order, "Commande {$order->number} validée", "Votre commande {$order->number} est validée."))
            ->and($notifications[0]->read_at)->toBeNull();
    }
    // Ni le compte désactivé, ni l'ancien compte du client, ni un autre client.
    foreach ([$this->inactiveA, $this->formerA, $this->accountB, $this->accountC] as $nobody) {
        expect(($this->notificationsOf)($nobody))->toBeEmpty();
    }
    expect(($this->countOf)())->toBe(2);

    // Dans le centre de notifications du client, au format du contrat.
    $this->actingAs($this->secondA)
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.0', [
            'id' => ($this->notificationsOf)($this->secondA)->sole()->id,
            'type' => 'ORDER_DECIDED',
            'code' => 'N-07',
            'title' => "Commande {$order->number} validée",
            'body' => "Votre commande {$order->number} est validée.",
            'url' => "/client/commandes/{$order->public_id}",
            'read_at' => null,
            'created_at' => '2026-10-08T08:30:00+00:00',
        ]);
});

it('sends N-07 « validée avec n lignes ajustées » when lines are validated under the quantity requested', function () {
    $two = ($this->submit)($this->a, [[40, 'orange'], [10, 'pomme']]);
    $one = ($this->submit)($this->b, [[40, 'orange'], [10, 'pomme']]);
    $whole = ($this->submit)($this->c, [[40, 'orange'], [10, 'pomme']]);

    // Deux lignes sur deux réduites (dont une à 0) ; une seule ligne réduite ; aucune.
    validateOrder($two, [$this->orange->id => 30, $this->pomme->id => 0]);
    validateOrder($one, [$this->orange->id => 25]);
    validateOrder($whole);

    expect(($this->contentOf)(($this->notificationsOf)($this->accountA)[0]))
        ->toBe(($this->expected)($two, "Commande {$two->number} validée", "Votre commande {$two->number} est validée (2 lignes ajustées)."))
        ->and(($this->contentOf)(($this->notificationsOf)($this->secondA)->sole()))
        ->toBe(($this->expected)($two, "Commande {$two->number} validée", "Votre commande {$two->number} est validée (2 lignes ajustées)."))
        ->and(($this->contentOf)(($this->notificationsOf)($this->accountB)->sole()))
        ->toBe(($this->expected)($one, "Commande {$one->number} validée", "Votre commande {$one->number} est validée (1 ligne ajustée)."))
        ->and(($this->contentOf)(($this->notificationsOf)($this->accountC)->sole()))
        ->toBe(($this->expected)($whole, "Commande {$whole->number} validée", "Votre commande {$whole->number} est validée."));
});

it('sends N-07 « rejetée » when the regional rejects the order, also to a client blocked since (I-53)', function () {
    $order = ($this->submit)($this->a);
    $this->a->forceFill(['status' => 'BLOCKED'])->save();

    ($this->reject)($order);

    foreach ([$this->accountA, $this->secondA] as $recipient) {
        expect(($this->contentOf)(($this->notificationsOf)($recipient)->sole()))
            ->toBe(($this->expected)($order, "Commande {$order->number} rejetée", "Votre commande {$order->number} est rejetée."));
    }
    expect(($this->countOf)())->toBe(2);
});

it('sends N-07 « annulée » when the ADV cancels a validated order, after the validation one, and no N-06 (RG-V07)', function () {
    $order = ($this->submit)($this->a);
    validateOrder($order);
    $this->travel(1)->minutes();

    ($this->cancelByAdv)($order);

    foreach ([$this->accountA, $this->secondA] as $recipient) {
        expect(($this->notificationsOf)($recipient)->map(fn (DatabaseNotification $n) => $n->data['body'])->all())->toBe([
            "Votre commande {$order->number} est validée.",
            "Votre commande {$order->number} est annulée.",
        ]);
    }
    expect(($this->contentOf)(($this->notificationsOf)($this->accountA)[1]))
        ->toBe(($this->expected)($order, "Commande {$order->number} annulée", "Votre commande {$order->number} est annulée."))
        ->and(($this->countOf)())->toBe(4)
        // N-06 (« X a annulé sa commande ») est réservé à l'annulation par le client.
        ->and(($this->countOf)('ORDER_CHANGED'))->toBe(0)
        ->and(($this->notificationsOf)($this->regional, 'ORDER_CHANGED'))->toBeEmpty()
        // Le régional garde N-05 de l'envoi, rien de plus.
        ->and(($this->notificationsOf)($this->regional, 'ORDER_SUBMITTED'))->toHaveCount(1);
});

it('sends N-07 once per order, version and recipient when the event, the job or the action is replayed (§9.7)', function () {
    $order = ($this->submit)($this->a);
    validateOrder($order);
    $validated = new OrderValidated($order->id, $this->a->id, $this->centre->id, $this->businessDay->id, 2, $this->regional->id, 0);

    // Événement rediffusé, tâche relancée.
    event($validated);
    app(NotifyOrderDecided::class)->handle($validated);
    expect(($this->countOf)())->toBe(2);

    // Nouvelle version (annulation par l'ADV) : notifiée à son tour, une seule fois.
    ($this->cancelByAdv)($order);
    $cancelled = new OrderCancelledByAdv($order->id, $this->a->id, $this->centre->id, $this->businessDay->id, 3, $this->adv->id);
    event($cancelled);
    app(NotifyOrderDecided::class)->handle($cancelled);
    app(NotifyOrderDecided::class)->handle($validated);

    expect(($this->countOf)())->toBe(4)
        ->and(($this->notificationsOf)($this->accountA))->toHaveCount(2)
        ->and(($this->notificationsOf)($this->secondA))->toHaveCount(2);
});

it('builds the notification from the event, not from the order, which may have moved on when the job runs', function () {
    $order = ($this->submit)($this->a, [[40, 'orange'], [10, 'pomme']]);
    Queue::fake();
    validateOrder($order, [$this->orange->id => 30]);
    // L'ADV prépare la commande avant que la tâche ne s'exécute : elle n'est plus « Validée ».
    app(AdvOrders::class)->transition($this->adv, OrderStatus::InPreparation, [['id' => $order->public_id, 'version' => 2]]);
    expect($order->fresh()->status)->toBe(OrderStatus::InPreparation)
        ->and(($this->countOf)())->toBe(0);

    $job = Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === NotifyOrderDecided::class)->sole();
    app(NotifyOrderDecided::class)->handle($job->data[0]);

    expect(($this->contentOf)(($this->notificationsOf)($this->accountA)->sole()))
        ->toBe(($this->expected)($order, "Commande {$order->number} validée", "Votre commande {$order->number} est validée (1 ligne ajustée)."));
});

it('sends N-07 from a background job, never during the decision (EF-NOT-06)', function () {
    $validated = ($this->submit)($this->a, [[40, 'orange'], [10, 'pomme']]);
    $rejected = ($this->submit)($this->b);
    $cancelled = ($this->submit)($this->c);
    validateOrder($cancelled);
    $before = ($this->countOf)();
    Queue::fake();

    validateOrder($validated, [$this->orange->id => 30]);
    ($this->reject)($rejected);
    ($this->cancelByAdv)($cancelled);

    foreach ([
        [OrderValidated::class, $validated, 2],
        [OrderRejected::class, $rejected, 2],
        [OrderCancelledByAdv::class, $cancelled, 3],
    ] as [$event, $order, $version]) {
        Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === NotifyOrderDecided::class
            && $job->data[0] instanceof $event
            && $job->data[0]->orderId === $order->id
            && $job->data[0]->version === $version
            // Relancée après un échec technique, sans double envoi.
            && $job->tries === 3
            && $job->backoff === [10, 60]);
    }
    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === NotifyOrderDecided::class, 3);
    // Rien n'est envoyé pendant l'action.
    expect(($this->countOf)())->toBe($before);
});

it('ignores a decision whose order no longer exists', function () {
    app(NotifyOrderDecided::class)->handle(new OrderRejected(999_999, $this->a->id, $this->centre->id, $this->businessDay->id, 2, null));
    app(NotifyOrderDecided::class)->handle(new OrderValidated(999_999, $this->a->id, $this->centre->id, $this->businessDay->id, 2, null, 1));

    expect(($this->countOf)())->toBe(0);
});

it('notifies a decision with a bounded number of queries, one pair per recipient beyond the reads (EF-NOT-06)', function () {
    foreach (range(1, 8) as $ignored) {
        userWithRole(Role::Client, ['client_id' => $this->a->id]);
    }
    $order = ($this->submit)($this->a);
    Queue::fake();
    validateOrder($order);
    $event = new OrderValidated($order->id, $this->a->id, $this->centre->id, $this->businessDay->id, 2, $this->regional->id, 0);

    DB::enableQueryLog();
    app(NotifyOrderDecided::class)->handle($event);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // La commande, les comptes (avec leurs rôles) ; par compte : clé d'unicité et enregistrement.
    expect(($this->countOf)())->toBe(10)
        ->and($queries)->toBeLessThanOrEqual(2 * 10 + 3);
});
