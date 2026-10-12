<?php

use App\Domain\Access\Role;
use App\Domain\Days\CutoffKind;
use App\Domain\Days\DayCalendar;
use App\Domain\Limits\DistributionResult;
use App\Domain\Limits\LimitBook;
use App\Domain\Quotas\QuotaBook;
use App\Events\DistributionPublished;
use App\Events\Realtime\DistributionPublished as RealtimeDistributionPublished;
use App\Events\Realtime\LimitDraftsUpdated;
use App\Events\Realtime\LimitsUpdated;
use App\Listeners\NotifyDistributionPublished;
use App\Models\Client;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionDay;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

// Notifications N-03 et N-04 aux comptes des clients (§7.8, RG-B06, RG-B07,
// EF-NOT-06 ; contrat du lot 4, §4).

beforeEach(function () {
    // Jeudi 8 octobre 2026, 08:30 à Alger : commande jusqu'à 13:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 08:30', 'Africa/Algiers'));
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    $this->regional = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['region_id' => $this->centre->id, 'user_id' => $this->regional->id]);

    $this->a = Client::factory()->create(['code' => 'C0012', 'region_id' => $this->centre->id]);
    $this->b = Client::factory()->create(['code' => 'C0047', 'region_id' => $this->centre->id]);
    $this->orange = Product::factory()->create(['sku' => 'P0042']);
    $this->pomme = Product::factory()->create(['sku' => 'P0057']);
    // Comptes clients : deux pour A (un client peut avoir plusieurs comptes), un pour B.
    $this->accountA = userWithRole(Role::Client, ['client_id' => $this->a->id]);
    $this->secondA = userWithRole(Role::Client, ['client_id' => $this->a->id]);
    $this->accountB = userWithRole(Role::Client, ['client_id' => $this->b->id]);

    $this->calendar = app(DayCalendar::class);
    $this->book = app(LimitBook::class);
    // Journée ouverte, quotas du Centre publiés par l'ADV (N-01 au régional).
    $this->open = function (string $date): RegionDay {
        $day = $this->calendar->open(BusinessClock::parseDay($date), $this->adv);
        $quotas = app(QuotaBook::class);
        $quotas->saveDrafts($day, [
            ['region_id' => $this->centre->id, 'product_id' => $this->orange->id, 'quantity' => 500],
            ['region_id' => $this->centre->id, 'product_id' => $this->pomme->id, 'quantity' => 300],
        ], $this->adv);
        $quotas->publish($day, [$this->centre->id], $this->adv);

        return RegionDay::query()->where('business_day_id', $day->id)->where('region_id', $this->centre->id)->sole();
    };
    $this->regionDay = ($this->open)('2026-10-08');
    // Bornes [client, produit, borne] en brouillon, puis envoi par le régional ;
    // une minute passe ensuite (notifications ordonnées).
    $this->send = function (array $limits = []): DistributionResult {
        $cells = array_map(fn (array $limit) => ['client_id' => $limit[0]->id, 'product_id' => $limit[1]->id, 'limit' => $limit[2]], $limits);
        $this->book->saveDrafts($this->regionDay, $cells, $this->regional);
        $result = $this->book->publish($this->regionDay, $this->regional);
        $this->travel(1)->minutes();

        return $result;
    };
    // Notifications de répartition seulement : le régional reçoit aussi N-01.
    $this->notificationsOf = fn (User $user) => DatabaseNotification::query()
        ->whereMorphedTo('notifiable', $user)
        ->whereIn('type', ['DISTRIBUTION_PUBLISHED', 'LIMITS_UPDATED'])
        ->orderBy('created_at')
        ->get();
    $this->clientNotifications = fn () => DatabaseNotification::query()->whereIn('type', ['DISTRIBUTION_PUBLISHED', 'LIMITS_UPDATED']);
    // Type et données enregistrées, clés triées : MySQL réordonne les clés JSON.
    $this->contentOf = fn (DatabaseNotification $notification) => [$notification->type, collect($notification->data)->sortKeys()->all()];
    $this->content = fn (string $type, array $data) => [$type, collect($data)->sortKeys()->all()];
    $this->n03 = ($this->content)('DISTRIBUTION_PUBLISHED', [
        'type' => 'DISTRIBUTION_PUBLISHED',
        'code' => 'N-03',
        'title' => 'Quotas du 08/10 disponibles',
        'body' => 'Vos quotas du 08/10 sont disponibles. Commandez avant 13:00.',
        'url' => '/client',
    ]);
});

it('sends N-03 to the active accounts of the notified clients only, from the publication to the notification center (RG-B06)', function () {
    // Temps réel simulé : la tâche de notification, elle, s'exécute (file synchrone).
    Event::fake([RealtimeDistributionPublished::class, LimitsUpdated::class, LimitDraftsUpdated::class]);
    // Ne reçoivent rien : compte désactivé, client bloqué (non notifié), client
    // d'une autre région, compte rattaché à A passé à un autre rôle, régional.
    $inactive = userWithRole(Role::Client, ['client_id' => $this->a->id, 'is_active' => false]);
    $blocked = Client::factory()->blocked()->create(['code' => 'C0050', 'region_id' => $this->centre->id]);
    $blockedAccount = userWithRole(Role::Client, ['client_id' => $blocked->id]);
    $elsewhere = Client::factory()->create(['code' => 'C0099', 'region_id' => $this->est->id]);
    $elsewhereAccount = userWithRole(Role::Client, ['client_id' => $elsewhere->id]);
    $formerClient = userWithRole(Role::Adv, ['client_id' => $this->a->id]);

    $result = ($this->send)([[$this->a, $this->orange, 300]]);

    expect($result->notifiedClientIds)->toBe([$this->a->id, $this->b->id]);
    foreach ([$this->accountA, $this->secondA, $this->accountB] as $recipient) {
        $notifications = ($this->notificationsOf)($recipient);
        expect($notifications)->toHaveCount(1)
            ->and(($this->contentOf)($notifications[0]))->toBe($this->n03)
            ->and($notifications[0]->read_at)->toBeNull();
    }
    foreach ([$inactive, $blockedAccount, $elsewhereAccount, $formerClient, $this->regional, $this->adv] as $nobody) {
        expect(($this->notificationsOf)($nobody))->toBeEmpty();
    }

    // Dans le centre de notifications du client, au format du contrat.
    $this->actingAs($this->accountA)
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('meta.unread_count', 1)
        ->assertJsonPath('data', [[
            'id' => ($this->notificationsOf)($this->accountA)->sole()->id,
            'type' => 'DISTRIBUTION_PUBLISHED',
            'code' => 'N-03',
            'title' => 'Quotas du 08/10 disponibles',
            'body' => 'Vos quotas du 08/10 sont disponibles. Commandez avant 13:00.',
            'url' => '/client',
            'read_at' => null,
            'created_at' => '2026-10-08T07:30:00+00:00',
        ]]);
});

it('announces the ordering cutoff applicable to the region, ADV extension included (RG-A05)', function () {
    $this->calendar->extendCutoff($this->regionDay, CutoffKind::Ordering, '13:30', $this->adv);
    // Prolongation de la répartition : sans effet sur l'heure annoncée aux clients.
    $this->calendar->extendCutoff($this->regionDay, CutoffKind::Distribution, '11:00', $this->adv);

    ($this->send)();

    expect(($this->notificationsOf)($this->accountB)->sole()->data['body'])
        ->toBe('Vos quotas du 08/10 sont disponibles. Commandez avant 13:30.');
});

it('notifies only the accounts of the clients of the event (RG-B04 : politique OBLIGATOIRE)', function () {
    // LimitBook ne retient que B (seul client ayant une borne > 0).
    app(NotifyDistributionPublished::class)->handle(new DistributionPublished($this->regionDay->id, 1, true, [$this->b->id], $this->regional->id));

    expect(($this->notificationsOf)($this->accountB))->toHaveCount(1)
        ->and(($this->notificationsOf)($this->accountA))->toBeEmpty()
        ->and(($this->notificationsOf)($this->secondA))->toBeEmpty();
});

it('never notifies a client blocked between the sending and the job (contrat §7)', function () {
    // Le client était actif à l'envoi ; l'ADV le bloque avant l'exécution de la tâche.
    $event = new DistributionPublished($this->regionDay->id, 1, true, [$this->a->id, $this->b->id], $this->regional->id);
    $this->a->forceFill(['status' => 'BLOCKED'])->save();

    app(NotifyDistributionPublished::class)->handle($event);

    expect(($this->notificationsOf)($this->accountA))->toBeEmpty()
        ->and(($this->notificationsOf)($this->secondA))->toBeEmpty()
        ->and(($this->notificationsOf)($this->accountB))->toHaveCount(1);
});

it('sends N-04 to the impacted clients only on each later publication (RG-B07)', function () {
    $blocked = Client::factory()->blocked()->create(['code' => 'C0050', 'region_id' => $this->centre->id]);
    $blockedAccount = userWithRole(Role::Client, ['client_id' => $blocked->id]);

    ($this->send)([[$this->a, $this->orange, 300], [$this->b, $this->orange, 200]]);
    ($this->send)([[$this->a, $this->orange, 250], [$this->a, $this->pomme, 0]]);
    ($this->send)([[$this->b, $this->pomme, 100]]);
    // Borne d'un client bloqué : envoyée, sans notification (client non actif).
    ($this->send)([[$blocked, $this->orange, 50]]);

    $notifications = ($this->notificationsOf)($this->accountA);
    expect($notifications->pluck('type')->all())->toBe(['DISTRIBUTION_PUBLISHED', 'LIMITS_UPDATED'])
        ->and(($this->contentOf)($notifications[1]))->toBe(($this->content)('LIMITS_UPDATED', [
            'type' => 'LIMITS_UPDATED',
            'code' => 'N-04',
            'title' => 'Quotas du 08/10 mis à jour',
            'body' => 'Vos quotas du jour ont été mis à jour.',
            'url' => '/client',
        ]))
        ->and(($this->notificationsOf)($this->secondA)->pluck('type')->all())->toBe(['DISTRIBUTION_PUBLISHED', 'LIMITS_UPDATED'])
        ->and(($this->notificationsOf)($this->accountB)->pluck('type')->all())->toBe(['DISTRIBUTION_PUBLISHED', 'LIMITS_UPDATED'])
        ->and(($this->notificationsOf)($blockedAccount))->toBeEmpty()
        ->and(($this->clientNotifications)()->count())->toBe(6);
});

it('never sends the same notification twice when an event or a job is replayed (§9.7)', function () {
    ($this->send)();
    $published = new DistributionPublished($this->regionDay->id, 1, true, [$this->a->id, $this->b->id], $this->regional->id);

    // Événement rediffusé, tâche relancée : rien de plus.
    event($published);
    app(NotifyDistributionPublished::class)->handle($published);
    app(NotifyDistributionPublished::class)->handle($published);
    foreach ([$this->accountA, $this->secondA, $this->accountB] as $recipient) {
        expect(($this->notificationsOf)($recipient))->toHaveCount(1);
    }

    // Une nouvelle version, elle, se notifie, une seule fois.
    $updated = new DistributionPublished($this->regionDay->id, 2, false, [$this->a->id], $this->regional->id);
    event($updated);
    app(NotifyDistributionPublished::class)->handle($updated);
    expect(($this->notificationsOf)($this->accountA)->pluck('type')->all())->toBe(['DISTRIBUTION_PUBLISHED', 'LIMITS_UPDATED'])
        ->and(($this->notificationsOf)($this->accountB))->toHaveCount(1)
        ->and(($this->clientNotifications)()->count())->toBe(5);
});

it('sends the notifications from a background job (EF-NOT-06)', function () {
    Queue::fake();

    ($this->send)([[$this->a, $this->orange, 300]]);
    ($this->send)([[$this->a, $this->orange, 250]]);

    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === NotifyDistributionPublished::class
        && $job->data[0] instanceof DistributionPublished
        && $job->data[0]->first
        && $job->data[0]->notifiedClientIds === [$this->a->id, $this->b->id]
        // Relancée après un échec technique, sans double envoi.
        && $job->tries === 3);
    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === NotifyDistributionPublished::class
        && ! $job->data[0]->first
        && $job->data[0]->version === 2
        && $job->data[0]->notifiedClientIds === [$this->a->id]);
    // Rien n'est envoyé pendant la requête du régional.
    expect(($this->clientNotifications)()->count())->toBe(0);
});

it('notifies the 150 clients of a region without one query per recipient beyond the notification itself (EF-NOT-06)', function () {
    $clients = Client::factory()->count(150)->create(['region_id' => $this->centre->id]);
    foreach ($clients as $client) {
        userWithRole(Role::Client, ['client_id' => $client->id]);
    }
    // Diffusions laissées en file : seule la tâche de notification est mesurée.
    Queue::fake();

    DB::enableQueryLog();
    app(NotifyDistributionPublished::class)->handle(new DistributionPublished($this->regionDay->id, 1, true, $clients->modelKeys(), $this->regional->id));
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Par destinataire : contrôle de la clé d'unicité et enregistrement.
    expect(($this->clientNotifications)()->count())->toBe(150)
        ->and($queries)->toBeLessThanOrEqual(2 * 150 + 5);
});

it('ignores an event whose region day no longer exists, or without client to notify', function () {
    app(NotifyDistributionPublished::class)->handle(new DistributionPublished(999_999, 1, true, [$this->a->id], null));
    app(NotifyDistributionPublished::class)->handle(new DistributionPublished($this->regionDay->id, 2, false, [], null));

    expect(($this->clientNotifications)()->count())->toBe(0);
});
