<?php

use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Domain\Limits\LimitBook;
use App\Domain\Quotas\QuotaBook;
use App\Events\DistributionPublished;
use App\Events\LimitDraftsSaved;
use App\Events\Realtime\DistributionPublished as RealtimeDistributionPublished;
use App\Events\Realtime\LimitDraftsUpdated;
use App\Events\Realtime\LimitsUpdated;
use App\Listeners\BroadcastDistributionActivity;
use App\Models\Client;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionDay;
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

// Diffusion temps réel de la répartition (§9.6 ; contrat du lot 4, §4) :
// DistributionPublished sur region-day.{regionId}.{day} et adv.{day},
// LimitDraftsUpdated sur region-day.{regionId}.{day}, LimitsUpdated sur
// client.{clientId} de chaque client notifié, N-03 et N-04 sur user.{id}.
// Identifiants et résumé seulement (EF-NOT-03).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 08:30', 'Africa/Algiers'));
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->regional = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['region_id' => $this->centre->id, 'user_id' => $this->regional->id]);
    $this->a = Client::factory()->create(['code' => 'C0012', 'region_id' => $this->centre->id]);
    $this->b = Client::factory()->create(['code' => 'C0047', 'region_id' => $this->centre->id]);
    $this->accountA = userWithRole(Role::Client, ['client_id' => $this->a->id]);
    $this->orange = Product::factory()->create(['sku' => 'P0042']);

    $this->calendar = app(DayCalendar::class);
    $this->book = app(LimitBook::class);
    // Journée ouverte, quota du Centre publié par l'ADV.
    $this->open = function (string $date): RegionDay {
        $day = $this->calendar->open(BusinessClock::parseDay($date), $this->adv);
        $quotas = app(QuotaBook::class);
        $quotas->saveDrafts($day, [['region_id' => $this->centre->id, 'product_id' => $this->orange->id, 'quantity' => 500]], $this->adv);
        $quotas->publish($day, [$this->centre->id], $this->adv);

        return RegionDay::query()->where('business_day_id', $day->id)->where('region_id', $this->centre->id)->sole();
    };
    $this->regionDay = ($this->open)('2026-10-08');
    $this->draft = fn (Client $client, ?int $limit, ?RegionDay $regionDay = null) => $this->book->saveDrafts($regionDay ?? $this->regionDay, [
        ['client_id' => $client->id, 'product_id' => $this->orange->id, 'limit' => $limit],
    ], $this->regional);
    $this->publish = fn () => $this->book->publish($this->regionDay, $this->regional);
    $this->realtime = [RealtimeDistributionPublished::class, LimitDraftsUpdated::class, LimitsUpdated::class];
    $this->channels = fn (ShouldBroadcast $event) => array_map(fn ($channel) => $channel->name, $event->broadcastOn());
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

it('broadcasts DistributionPublished to the region day and to the ADV follow-up of the day', function () {
    Event::fake($this->realtime);

    ($this->publish)();
    ($this->draft)($this->a, 300);
    ($this->publish)();

    $events = Event::dispatched(RealtimeDistributionPublished::class)->map(fn (array $call) => $call[0]);
    expect($events)->toHaveCount(2)
        ->and(($this->channels)($events[0]))->toBe(["private-region-day.{$this->centre->id}.2026-10-08", 'private-adv.2026-10-08'])
        ->and($events[0]->broadcastAs())->toBe('DistributionPublished')
        ->and($events[0]->broadcastWith())->toBe(['region_id' => $this->centre->id, 'day' => '2026-10-08', 'version' => 1, 'first' => true])
        ->and($events[1]->broadcastWith())->toBe(['region_id' => $this->centre->id, 'day' => '2026-10-08', 'version' => 2, 'first' => false]);
});

it('broadcasts LimitsUpdated to the channel of each notified client, with the day and the version', function () {
    Event::fake($this->realtime);
    $blocked = Client::factory()->blocked()->create(['region_id' => $this->centre->id]);

    // Premier envoi : tous les clients actifs de la région (RG-B06).
    ($this->publish)();
    // Envoi suivant : les clients actifs impactés seulement (RG-B07).
    ($this->draft)($this->b, 200);
    ($this->publish)();
    // Client bloqué impacté : non notifié, rien sur son canal.
    ($this->draft)($blocked, 50);
    ($this->publish)();

    $events = Event::dispatched(LimitsUpdated::class)->map(fn (array $call) => $call[0]);
    expect($events)->toHaveCount(2)
        ->and(($this->channels)($events[0]))->toBe(["private-client.{$this->a->id}", "private-client.{$this->b->id}"])
        ->and($events[0]->broadcastAs())->toBe('LimitsUpdated')
        ->and($events[0]->broadcastWith())->toBe(['day' => '2026-10-08', 'version' => 1])
        ->and(($this->channels)($events[1]))->toBe(["private-client.{$this->b->id}"])
        ->and($events[1]->broadcastWith())->toBe(['day' => '2026-10-08', 'version' => 2]);
    // Le suivi de la région, lui, voit chaque envoi.
    Event::assertDispatchedTimes(RealtimeDistributionPublished::class, 3);
});

it('broadcasts LimitDraftsUpdated to the region day, and to the ADV only on the first draft, when a draft changes or « Reprendre J-1 » fills the grid', function () {
    Event::fake($this->realtime);

    expect(($this->draft)($this->a, 300))->toBe(1);
    // Aucune cellule changée : rien n'est diffusé.
    expect(($this->draft)($this->a, 300))->toBe(0);
    expect(($this->draft)($this->a, 250))->toBe(1);

    Event::assertDispatchedTimes(LimitDraftsUpdated::class, 2);
    [$first, $event] = Event::dispatched(LimitDraftsUpdated::class)->map(fn (array $args) => $args[0])->all();
    // Premier brouillon : la région passe « Répartition en cours », visible de l'ADV (§6.1).
    expect(($this->channels)($first))->toBe(["private-region-day.{$this->centre->id}.2026-10-08", 'private-adv.2026-10-08'])
        ->and(($this->channels)($event))->toBe(["private-region-day.{$this->centre->id}.2026-10-08"])
        ->and($event->broadcastAs())->toBe('LimitDraftsUpdated')
        ->and($event->broadcastWith())->toBe(['region_id' => $this->centre->id, 'day' => '2026-10-08']);
    // L'enregistrement d'un brouillon ne touche pas les clients.
    Event::assertNotDispatched(LimitsUpdated::class);
    Event::assertNotDispatched(RealtimeDistributionPublished::class);

    // « Reprendre J-1 » (RG-B08) le lendemain : la grille du 09/10 se relit.
    ($this->publish)();
    $tomorrow = ($this->open)('2026-10-09');
    expect($this->book->copyPrevious($tomorrow, true, $this->regional)['changed'])->toBe(1);
    $event = Event::dispatched(LimitDraftsUpdated::class)->last()[0];
    expect(($this->channels)($event))->toBe(["private-region-day.{$this->centre->id}.2026-10-09", 'private-adv.2026-10-09'])
        ->and($event->broadcastWith())->toBe(['region_id' => $this->centre->id, 'day' => '2026-10-09']);
});

it('broadcasts from the queue, never during the request', function () {
    foreach ($this->realtime as $class) {
        expect(is_subclass_of($class, ShouldBroadcast::class))->toBeTrue()
            ->and(is_subclass_of($class, ShouldBroadcastNow::class))->toBeFalse();
    }

    Queue::fake();
    ($this->draft)($this->a, 300);
    ($this->publish)();

    Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job) => $job->event instanceof LimitDraftsUpdated);
    Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job) => $job->event instanceof RealtimeDistributionPublished);
    Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job) => $job->event instanceof LimitsUpdated);
});

it('delivers the events and the N-03 notification to the broadcaster with identifiers and a summary only', function () {
    $recorder = ($this->recorder)();

    ($this->draft)($this->a, 300);
    ($this->publish)();

    $sent = collect($recorder->sent);
    expect($sent->firstWhere('event', 'LimitDraftsUpdated'))->toBe([
        'channels' => ["private-region-day.{$this->centre->id}.2026-10-08", 'private-adv.2026-10-08'],
        'event' => 'LimitDraftsUpdated',
        'payload' => ['region_id' => $this->centre->id, 'day' => '2026-10-08'],
    ])->and($sent->firstWhere('event', 'DistributionPublished'))->toBe([
        'channels' => ["private-region-day.{$this->centre->id}.2026-10-08", 'private-adv.2026-10-08'],
        'event' => 'DistributionPublished',
        'payload' => ['region_id' => $this->centre->id, 'day' => '2026-10-08', 'version' => 1, 'first' => true],
    ])->and($sent->firstWhere('event', 'LimitsUpdated'))->toBe([
        'channels' => ["private-client.{$this->a->id}", "private-client.{$this->b->id}"],
        'event' => 'LimitsUpdated',
        'payload' => ['day' => '2026-10-08', 'version' => 1],
    ]);

    // N-03 sur le canal personnel du compte client : le contenu du centre de notifications.
    $notifications = $sent->where('event', 'Illuminate\Notifications\Events\BroadcastNotificationCreated')->values();
    expect($notifications)->toHaveCount(1)
        ->and($notifications[0]['channels'])->toBe(["private-user.{$this->accountA->id}"])
        ->and($notifications[0]['payload'])->toBe([
            'type' => 'DISTRIBUTION_PUBLISHED',
            'code' => 'N-03',
            'title' => 'Quotas du 08/10 disponibles',
            'body' => 'Vos quotas du 08/10 sont disponibles. Commandez avant 13:00.',
            'url' => '/client',
            'read_at' => null,
            'created_at' => '2026-10-08T07:30:00+00:00',
            'id' => DatabaseNotification::query()->where('type', 'DISTRIBUTION_PUBLISHED')->sole()->id,
        ]);
});

it('never makes a saved draft or a sent distribution fail when the realtime relay fails', function () {
    Exceptions::fake();
    // Seule la tâche de notification est retenue : les diffusions passent par
    // la file synchrone, comme si Reverb était arrêté pendant la requête.
    Queue::fake([CallQueuedListener::class]);
    $recorder = ($this->recorder)(['LimitDraftsUpdated', 'DistributionPublished']);

    expect(($this->draft)($this->a, 300))->toBe(1);
    $result = ($this->publish)();

    expect($result->version)->toBe(1)
        ->and($this->regionDay->fresh()->distribution_version)->toBe(1);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Reverb injoignable (LimitDraftsUpdated)');
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Reverb injoignable (DistributionPublished)');
    Exceptions::assertReportedCount(2);
    // Une diffusion en échec n'empêche pas les suivantes.
    expect(collect($recorder->sent)->pluck('event')->all())->toBe(['LimitsUpdated']);
});

it('ignores an event whose region day no longer exists', function () {
    Event::fake($this->realtime);

    $relay = app(BroadcastDistributionActivity::class);
    $relay->handleDistributionPublished(new DistributionPublished(999_999, 1, true, [$this->a->id], null));
    $relay->handleLimitDraftsSaved(new LimitDraftsSaved(999_999, null));

    Event::assertNothingDispatched();
});
