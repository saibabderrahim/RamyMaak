<?php

use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Domain\Quotas\QuotaBook;
use App\Events\Realtime\DayClosed;
use App\Events\Realtime\DraftsUpdated;
use App\Events\Realtime\QuotaPublished;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

// Diffusion temps réel (§9.6 ; contrat du lot 3, §6) : QuotaPublished sur
// region-day.{regionId}.{day} et adv.{day}, DraftsUpdated et DayClosed sur
// adv.{day}, notifications sur user.{id}. Identifiants et résumé seulement
// (EF-NOT-03).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 07:30', 'Africa/Algiers'));
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    $this->regional = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['region_id' => $this->centre->id, 'user_id' => $this->regional->id]);
    $this->orange = Product::factory()->create();
    $this->calendar = app(DayCalendar::class);
    $this->book = app(QuotaBook::class);
    $this->day = $this->calendar->open(BusinessClock::parseDay('2026-10-08'), $this->adv);
    $this->draft = fn (Region $region, int $quantity) => $this->book->saveDrafts($this->day, [
        ['region_id' => $region->id, 'product_id' => $this->orange->id, 'quantity' => $quantity],
    ], $this->adv);
    $this->channels = fn (ShouldBroadcast $event) => array_map(fn ($channel) => $channel->name, $event->broadcastOn());
});

it('broadcasts QuotaPublished to the region day and to the ADV follow-up of the day', function () {
    Event::fake([QuotaPublished::class, DraftsUpdated::class, DayClosed::class]);
    ($this->draft)($this->centre, 500);

    $this->book->publish($this->day, [$this->centre->id], $this->adv);
    ($this->draft)($this->centre, 450);
    $this->book->publish($this->day, [$this->centre->id], $this->adv);

    $events = Event::dispatched(QuotaPublished::class)->map(fn (array $call) => $call[0]);
    expect($events)->toHaveCount(2)
        ->and(($this->channels)($events[0]))->toBe(["private-region-day.{$this->centre->id}.2026-10-08", 'private-adv.2026-10-08'])
        ->and($events[0]->broadcastAs())->toBe('QuotaPublished')
        ->and($events[0]->broadcastWith())->toBe(['region_id' => $this->centre->id, 'day' => '2026-10-08', 'version' => 1, 'first' => true])
        ->and($events[1]->broadcastWith())->toBe(['region_id' => $this->centre->id, 'day' => '2026-10-08', 'version' => 2, 'first' => false]);
});

it('broadcasts DraftsUpdated to the ADV follow-up of the day only, with the changed regions', function () {
    Event::fake([QuotaPublished::class, DraftsUpdated::class, DayClosed::class]);

    $this->book->saveDrafts($this->day, [
        ['region_id' => $this->centre->id, 'product_id' => $this->orange->id, 'quantity' => 500],
        ['region_id' => $this->est->id, 'product_id' => $this->orange->id, 'quantity' => 300],
    ], $this->adv);
    // Aucune cellule changée : rien n'est diffusé.
    ($this->draft)($this->centre, 500);

    Event::assertDispatchedTimes(DraftsUpdated::class, 1);
    $event = Event::dispatched(DraftsUpdated::class)->first()[0];
    expect(($this->channels)($event))->toBe(['private-adv.2026-10-08'])
        ->and($event->broadcastAs())->toBe('DraftsUpdated')
        ->and($event->broadcastWith())->toBe(['region_ids' => [$this->centre->id, $this->est->id], 'day' => '2026-10-08']);
    Event::assertNotDispatched(QuotaPublished::class);
});

it('broadcasts DayClosed to the ADV follow-up of the day when it closes', function () {
    Event::fake([QuotaPublished::class, DraftsUpdated::class, DayClosed::class]);

    $this->travelTo(CarbonImmutable::parse('2026-10-08 23:00', 'Africa/Algiers'));
    $this->calendar->closeDue();
    // Rejouée : la journée n'est pas clôturée deux fois, ni annoncée deux fois.
    $this->calendar->closeDue();

    Event::assertDispatchedTimes(DayClosed::class, 1);
    $event = Event::dispatched(DayClosed::class)->first()[0];
    expect(($this->channels)($event))->toBe(['private-adv.2026-10-08'])
        ->and($event->broadcastAs())->toBe('DayClosed')
        ->and($event->broadcastWith())->toBe(['day' => '2026-10-08']);
});

it('broadcasts from the queue, never during the request', function () {
    foreach ([QuotaPublished::class, DraftsUpdated::class, DayClosed::class] as $class) {
        expect(is_subclass_of($class, ShouldBroadcast::class))->toBeTrue()
            ->and(is_subclass_of($class, ShouldBroadcastNow::class))->toBeFalse();
    }

    Queue::fake();
    ($this->draft)($this->centre, 500);
    $this->book->publish($this->day, [$this->centre->id], $this->adv);

    Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job) => $job->event instanceof DraftsUpdated);
    Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job) => $job->event instanceof QuotaPublished);
});

it('delivers the events and the notifications to the broadcaster with identifiers and a summary only', function () {
    // Diffuseur de test : enregistre ce qui partirait vers Reverb.
    $recorder = new class extends Broadcaster
    {
        /** @var list<array{channels: list<string>, event: string, payload: array<string, mixed>}> */
        public array $sent = [];

        public function auth($request) {}

        public function validAuthenticationResponse($request, $result) {}

        public function broadcast(array $channels, $event, array $payload = []): void
        {
            unset($payload['socket']);
            $this->sent[] = ['channels' => $this->formatChannels($channels), 'event' => $event, 'payload' => $payload];
        }
    };
    Broadcast::extend('recording', fn () => $recorder);
    config(['broadcasting.default' => 'recording', 'broadcasting.connections.recording' => ['driver' => 'recording']]);

    ($this->draft)($this->centre, 500);
    $this->book->publish($this->day, [$this->centre->id], $this->adv);

    $sent = collect($recorder->sent);
    expect($sent->firstWhere('event', 'DraftsUpdated'))->toBe([
        'channels' => ['private-adv.2026-10-08'],
        'event' => 'DraftsUpdated',
        'payload' => ['region_ids' => [$this->centre->id], 'day' => '2026-10-08'],
    ])->and($sent->firstWhere('event', 'QuotaPublished'))->toBe([
        'channels' => ["private-region-day.{$this->centre->id}.2026-10-08", 'private-adv.2026-10-08'],
        'event' => 'QuotaPublished',
        'payload' => ['region_id' => $this->centre->id, 'day' => '2026-10-08', 'version' => 1, 'first' => true],
    ]);

    // N-01 sur le canal personnel du régional : le contenu du centre de notifications.
    $notification = $sent->firstWhere('event', 'Illuminate\Notifications\Events\BroadcastNotificationCreated');
    expect($notification['channels'])->toBe(["private-user.{$this->regional->id}"])
        ->and($notification['payload'])->toBe([
            'type' => 'QUOTAS_PUBLISHED',
            'code' => 'N-01',
            'title' => 'Quotas du 08/10 reçus — région Centre',
            'body' => 'À répartir avant 10:00.',
            'url' => '/regional/quotas?day=2026-10-08',
            'read_at' => null,
            'created_at' => '2026-10-08T06:30:00+00:00',
            'id' => DatabaseNotification::query()->sole()->id,
        ]);
});

it('never makes a saved action fail when the realtime relay fails', function () {
    Exceptions::fake();
    // Diffuseur en panne (Reverb arrêté, file synchrone).
    Broadcast::extend('down', fn () => new class extends Broadcaster
    {
        public function auth($request) {}

        public function validAuthenticationResponse($request, $result) {}

        public function broadcast(array $channels, $event, array $payload = []): void
        {
            throw new RuntimeException('Reverb injoignable');
        }
    });
    config(['broadcasting.default' => 'down', 'broadcasting.connections.down' => ['driver' => 'down']]);

    expect(($this->draft)($this->centre, 500))->toBe(1);

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Reverb injoignable');
});
