<?php

use App\Domain\Access\Role;
use App\Domain\Orders\ClientOrders;
use App\Events\OrderCancelled;
use App\Events\OrderSubmitted;
use App\Events\OrderUpdated;
use App\Events\Realtime\OrderCancelled as RealtimeOrderCancelled;
use App\Events\Realtime\OrdersExpired as RealtimeOrdersExpired;
use App\Events\Realtime\OrderStatusChanged;
use App\Events\Realtime\OrderSubmitted as RealtimeOrderSubmitted;
use App\Events\Realtime\OrderUpdated as RealtimeOrderUpdated;
use App\Listeners\NotifyOrderChanged;
use App\Listeners\NotifyOrderSubmitted;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Notifications N-05 et N-06 aux régionaux de la région de la commande
// (§7.8, RG-C05, I-57, EF-NOT-06 ; contrat du lot 5, §5).

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:30 à Alger : commande jusqu'à 13:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    // Temps réel simulé : les tâches de notification, elles, s'exécutent (file synchrone).
    Event::fake([RealtimeOrderSubmitted::class, RealtimeOrderUpdated::class, RealtimeOrderCancelled::class, OrderStatusChanged::class, RealtimeOrdersExpired::class]);
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);

    // Centre : un titulaire et un suppléant actifs le 08/10.
    $this->titular = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['region_id' => $this->centre->id, 'user_id' => $this->titular->id]);
    $this->deputy = userWithRole(Role::Regional);
    RegionAssignment::factory()->deputy('2026-10-07', '2026-10-15')->create(['region_id' => $this->centre->id, 'user_id' => $this->deputy->id]);

    $this->client = Client::factory()->create(['code' => 'C0012', 'name' => 'Superette El Baraka', 'region_id' => $this->centre->id]);
    $this->account = userWithRole(Role::Client, ['client_id' => $this->client->id]);
    $this->secondAccount = userWithRole(Role::Client, ['client_id' => $this->client->id]);
    $this->orange = Product::factory()->create(['sku' => 'P0042']);
    $this->pomme = Product::factory()->create(['sku' => 'P0057']);
    // Quotas publiés (N-01) et répartition envoyée (N-03) ; une minute passe ensuite.
    $this->regionDay = openOrderingDay('2026-10-08', $this->centre, [[$this->orange, 500], [$this->pomme, 300]]);
    $this->travel(1)->minutes();

    $this->orders = app(ClientOrders::class);
    $this->lines = fn (array $lines) => array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines);
    // Actions du client ; une minute passe ensuite (notifications ordonnées).
    $this->submit = function (array $lines, ?string $requestId = null, string $date = '2026-10-08', ?User $by = null): Order {
        $order = $this->orders->submit($by ?? $this->account, $requestId ?? (string) Str::uuid(), BusinessClock::parseDay($date), ($this->lines)($lines), null)['order'];
        $this->travel(1)->minutes();

        return $order;
    };
    $this->modify = function (Order $order, int $version, array $lines, ?string $comment = null): Order {
        $order = $this->orders->modify($this->account, $order->public_id, $version, ($this->lines)($lines), $comment);
        $this->travel(1)->minutes();

        return $order;
    };
    $this->cancel = function (Order $order, int $version, ?User $by = null): Order {
        $order = $this->orders->cancel($by ?? $this->account, $order->public_id, $version);
        $this->travel(1)->minutes();

        return $order;
    };
    // Notifications de commande seulement : le régional reçoit aussi N-01.
    $this->notificationsOf = fn (User $user) => DatabaseNotification::query()
        ->whereMorphedTo('notifiable', $user)
        ->whereIn('type', ['ORDER_SUBMITTED', 'ORDER_CHANGED'])
        ->orderBy('created_at')
        ->get();
    $this->orderNotifications = fn () => DatabaseNotification::query()->whereIn('type', ['ORDER_SUBMITTED', 'ORDER_CHANGED']);
    // Type et données enregistrées, clés triées : MySQL réordonne les clés JSON.
    $this->contentOf = fn (DatabaseNotification $notification) => [$notification->type, collect($notification->data)->sortKeys()->all()];
    $this->content = fn (string $type, array $data) => [$type, collect($data)->sortKeys()->all()];
});

it('sends N-05 to the titular and the active deputies of the region of the order only, from the sending to the notification center', function () {
    // Ne reçoivent rien : suppléance révoquée, terminée la veille ou à venir,
    // compte désactivé, compte passé à un autre rôle, régional d'une autre
    // région, ADV, comptes du client.
    $revoked = userWithRole(Role::Regional);
    RegionAssignment::factory()->deputy()->revoked()->create(['region_id' => $this->centre->id, 'user_id' => $revoked->id]);
    $expired = userWithRole(Role::Regional);
    RegionAssignment::factory()->deputy('2026-10-01', '2026-10-07')->create(['region_id' => $this->centre->id, 'user_id' => $expired->id]);
    $upcoming = userWithRole(Role::Regional);
    RegionAssignment::factory()->deputy('2026-10-09', '2026-10-15')->create(['region_id' => $this->centre->id, 'user_id' => $upcoming->id]);
    $inactive = userWithRole(Role::Regional, ['is_active' => false]);
    RegionAssignment::factory()->deputy()->create(['region_id' => $this->centre->id, 'user_id' => $inactive->id]);
    $formerRegional = userWithRole(Role::Regional);
    RegionAssignment::factory()->deputy()->create(['region_id' => $this->centre->id, 'user_id' => $formerRegional->id]);
    $formerRegional->syncRoles([Role::Adv->value]);
    $other = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['region_id' => $this->est->id, 'user_id' => $other->id]);

    $order = ($this->submit)([[$this->orange, 40], [$this->pomme, 3]]);

    $expected = ($this->content)('ORDER_SUBMITTED', [
        'type' => 'ORDER_SUBMITTED',
        'code' => 'N-05',
        'title' => 'Nouvelle commande — C0012 Superette El Baraka',
        'body' => 'RMK-261008-00001 à valider.',
        'url' => "/regional/commandes/{$order->public_id}",
    ]);
    foreach ([$this->titular, $this->deputy] as $recipient) {
        $notifications = ($this->notificationsOf)($recipient);
        expect($notifications)->toHaveCount(1)
            ->and(($this->contentOf)($notifications[0]))->toBe($expected)
            ->and($notifications[0]->read_at)->toBeNull();
    }
    foreach ([$revoked, $expired, $upcoming, $inactive, $formerRegional, $other, $this->adv, $this->account, $this->secondAccount] as $nobody) {
        expect(($this->notificationsOf)($nobody))->toBeEmpty();
    }

    // Dans le centre de notifications du régional, au format du contrat, sans montant.
    $this->actingAs($this->titular)
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.0', [
            'id' => ($this->notificationsOf)($this->titular)->sole()->id,
            'type' => 'ORDER_SUBMITTED',
            'code' => 'N-05',
            'title' => 'Nouvelle commande — C0012 Superette El Baraka',
            'body' => 'RMK-261008-00001 à valider.',
            'url' => "/regional/commandes/{$order->public_id}",
            'read_at' => null,
            'created_at' => '2026-10-08T08:31:00+00:00',
        ]);
});

it('notifies the regionals of the region on the day of the order, not on the day of the sending', function () {
    // Suppléant jusqu'au 08/10 seulement, suppléant à partir du 11/10.
    $untilToday = userWithRole(Role::Regional);
    RegionAssignment::factory()->deputy('2026-10-01', '2026-10-08')->create(['region_id' => $this->centre->id, 'user_id' => $untilToday->id]);
    $fromSunday = userWithRole(Role::Regional);
    RegionAssignment::factory()->deputy('2026-10-11', '2026-10-20')->create(['region_id' => $this->centre->id, 'user_id' => $fromSunday->id]);
    // Répartition du dimanche 11/10 envoyée dès le jeudi (I-42).
    openOrderingDay('2026-10-11', $this->centre, [[$this->orange, 500]]);

    $order = ($this->submit)([[$this->orange, 10]], date: '2026-10-11');

    expect($order->number)->toBe('RMK-261011-00001')
        ->and(($this->notificationsOf)($fromSunday)->sole()->data['body'])->toBe('RMK-261011-00001 à valider.')
        ->and(($this->notificationsOf)($this->titular))->toHaveCount(1)
        ->and(($this->notificationsOf)($this->deputy))->toHaveCount(1)
        ->and(($this->notificationsOf)($untilToday))->toBeEmpty();
});

it('sends N-06 when the client modifies or cancels its order, once per version (RG-C05, I-51)', function () {
    $order = ($this->submit)([[$this->orange, 40]]);
    ($this->modify)($order, 1, [[$this->orange, 30], [$this->pomme, 5]]);
    // Rien de changé : même version, pas de N-06 (I-51).
    expect(($this->modify)($order, 2, [[$this->pomme, 5], [$this->orange, 30]])->version)->toBe(2);
    ($this->modify)($order, 2, [[$this->orange, 30]], 'Livraison avant 10 h');
    ($this->cancel)($order, 3);

    $url = "/regional/commandes/{$order->public_id}";
    $notifications = ($this->notificationsOf)($this->titular);
    expect($notifications->pluck('type')->all())->toBe(['ORDER_SUBMITTED', 'ORDER_CHANGED', 'ORDER_CHANGED', 'ORDER_CHANGED'])
        ->and(($this->contentOf)($notifications[1]))->toBe(($this->content)('ORDER_CHANGED', [
            'type' => 'ORDER_CHANGED',
            'code' => 'N-06',
            'title' => 'Commande RMK-261008-00001 modifiée',
            'body' => 'Superette El Baraka a modifié sa commande.',
            'url' => $url,
        ]))
        ->and($notifications[2]->data['title'])->toBe('Commande RMK-261008-00001 modifiée')
        ->and(($this->contentOf)($notifications[3]))->toBe(($this->content)('ORDER_CHANGED', [
            'type' => 'ORDER_CHANGED',
            'code' => 'N-06',
            'title' => 'Commande RMK-261008-00001 annulée',
            'body' => 'Superette El Baraka a annulé sa commande.',
            'url' => $url,
        ]))
        ->and(($this->notificationsOf)($this->deputy)->pluck('data.title')->all())->toBe([
            'Nouvelle commande — C0012 Superette El Baraka',
            'Commande RMK-261008-00001 modifiée',
            'Commande RMK-261008-00001 modifiée',
            'Commande RMK-261008-00001 annulée',
        ])
        ->and(($this->notificationsOf)($this->account))->toBeEmpty();
});

it('names the client, not the account, when another account of the client cancels', function () {
    $order = ($this->submit)([[$this->orange, 40]]);

    ($this->cancel)($order, 1, $this->secondAccount);

    expect(($this->notificationsOf)($this->titular)->last()->data['body'])->toBe('Superette El Baraka a annulé sa commande.');
});

it('never sends the same notification twice when a request, an event or a job is replayed (§9.7)', function () {
    $requestId = (string) Str::uuid();
    $order = ($this->submit)([[$this->orange, 40]], $requestId);
    $submitted = new OrderSubmitted($order->id, $this->client->id, $this->centre->id, $order->business_day_id, 1, $this->account->id);

    // Renvoi du même envoi (RG-C07), événement rediffusé, tâche relancée : rien de plus.
    expect(($this->submit)([[$this->orange, 40]], $requestId)->id)->toBe($order->id);
    event($submitted);
    app(NotifyOrderSubmitted::class)->handle($submitted);
    expect(($this->notificationsOf)($this->titular))->toHaveCount(1)
        ->and(($this->notificationsOf)($this->deputy))->toHaveCount(1);

    // Deux modifications rapprochées : la tâche de la première, relancée après
    // la seconde, garde sa version.
    ($this->modify)($order, 1, [[$this->orange, 30]]);
    ($this->modify)($order, 2, [[$this->orange, 20]]);
    $first = new OrderUpdated($order->id, $this->client->id, $this->centre->id, $order->business_day_id, 2, $this->account->id);
    event($first);
    app(NotifyOrderChanged::class)->handle($first);
    app(NotifyOrderChanged::class)->handle(new OrderUpdated($order->id, $this->client->id, $this->centre->id, $order->business_day_id, 3, $this->account->id));
    expect(($this->notificationsOf)($this->titular)->where('type', 'ORDER_CHANGED'))->toHaveCount(2);

    ($this->cancel)($order, 3);
    app(NotifyOrderChanged::class)->handle(new OrderCancelled($order->id, $this->client->id, $this->centre->id, $order->business_day_id, 4, $this->account->id));
    expect(($this->notificationsOf)($this->titular)->pluck('type')->all())->toBe(['ORDER_SUBMITTED', 'ORDER_CHANGED', 'ORDER_CHANGED', 'ORDER_CHANGED'])
        ->and(($this->orderNotifications)()->count())->toBe(8);
});

it('sends the notifications from a background job (EF-NOT-06)', function () {
    Queue::fake();

    $order = ($this->submit)([[$this->orange, 40]]);
    ($this->modify)($order, 1, [[$this->orange, 30]]);
    ($this->cancel)($order, 2);

    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === NotifyOrderSubmitted::class
        && $job->data[0] instanceof OrderSubmitted
        && $job->data[0]->orderId === $order->id
        // Relancée après un échec technique, sans double envoi.
        && $job->tries === 3
        && $job->backoff === [10, 60]);
    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === NotifyOrderChanged::class
        && $job->data[0] instanceof OrderUpdated
        && $job->data[0]->version === 2
        && $job->tries === 3);
    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === NotifyOrderChanged::class
        && $job->data[0] instanceof OrderCancelled
        && $job->data[0]->version === 3);
    // Rien n'est envoyé pendant la requête du client.
    expect(($this->orderNotifications)()->count())->toBe(0);
});

it('ignores an event whose order no longer exists', function () {
    app(NotifyOrderSubmitted::class)->handle(new OrderSubmitted(999_999, $this->client->id, $this->centre->id, $this->regionDay->business_day_id, 1, null));
    app(NotifyOrderChanged::class)->handle(new OrderUpdated(999_999, $this->client->id, $this->centre->id, $this->regionDay->business_day_id, 2, null));
    app(NotifyOrderChanged::class)->handle(new OrderCancelled(999_999, $this->client->id, $this->centre->id, $this->regionDay->business_day_id, 2, null));

    expect(($this->orderNotifications)()->count())->toBe(0);
});
