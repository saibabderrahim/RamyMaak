<?php

use App\Domain\Access\Role;
use App\Domain\Push\FakePushTransport;
use App\Domain\Push\PushThrottler;
use App\Domain\Push\PushTransport;
use App\Domain\Push\QuietHours;
use App\Domain\Settings\Settings;
use App\Jobs\DeliverPush;
use App\Jobs\FlushPush;
use App\Models\PushSubscription;
use App\Models\PushThrottle;
use App\Models\User;
use App\Notifications\NotificationType;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Push\PushTesting;

// Push des notifications (contrat du lot 8, §2.3 à §2.5) : canal push,
// plage sans push, anti-saturation, résultats des envois.

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:30 à Alger.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    PushTesting::configureVapid();
    $this->push = FakePushTransport::install();
    $this->user = userWithRole(Role::Regional);
    $this->device = PushSubscription::factory()->create(['user_id' => $this->user->id]);
    $this->send = fn (NotificationType $type, string $key, string $title = 'Titre de test', ?User $to = null) => PushTesting::notification($type, $key, $title)->sendOnce([$to ?? $this->user]);
    $this->stored = fn (?User $user = null) => DatabaseNotification::query()->whereMorphedTo('notifiable', $user ?? $this->user)->count();
    $this->throttle = fn (string $group) => PushThrottle::query()->where('user_id', $this->user->id)->where('group', $group)->first();
    // Envoi groupé de la fin de la fenêtre, exécuté comme le ferait la file.
    $this->runFlush = function (): void {
        $jobs = Queue::pushed(FlushPush::class);
        expect($jobs)->not->toBeEmpty();
        $jobs->last()->handle(app(PushThrottler::class));
    };
});

it('pushes every pushable notification with the payload of the contract', function (NotificationType $type) {
    $notification = PushTesting::notification($type, 'k1', 'Commande RMK-261008-00042 validée', 'Votre commande RMK-261008-00042 est validée.', '/client/commandes/01J');
    $notification->sendOnce([$this->user]);

    $stored = DatabaseNotification::query()->whereMorphedTo('notifiable', $this->user)->sole();
    expect($this->push->sent)->toHaveCount(1)
        ->and($this->push->sent[0]['endpoints'])->toBe([$this->device->endpoint])
        ->and($this->push->sent[0]['payload'])->toBe([
            'id' => $stored->id,
            'type' => $type->value,
            'code' => $type->code(),
            'title' => 'Commande RMK-261008-00042 validée',
            'body' => 'Votre commande RMK-261008-00042 est validée.',
            'url' => '/client/commandes/01J',
            'tag' => $type->value,
            'count' => 1,
        ])
        ->and($this->push->sent[0]['options'])->toBe([
            'ttl' => 6 * 3600,
            // Les rappels réveillent l'appareil (§2.4).
            'urgency' => $type->isReminder() ? 'high' : 'normal',
            'topic' => $type->value,
        ]);

    $device = $this->device->fresh();
    expect($device->failures)->toBe(0)
        ->and($device->last_used_at?->toIso8601String())->toBe(now()->toIso8601String());
})->with(array_values(array_filter(NotificationType::cases(), fn (NotificationType $type) => $type->pushable())));

it('keeps ORDER_CHANGED, DISTRIBUTION_LATE and ACCOUNT_SECURITY in the notification center only', function (NotificationType $type) {
    ($this->send)($type, 'k1');

    expect(($this->stored)())->toBe(1)
        ->and($this->push->sent)->toBeEmpty()
        ->and(PushThrottle::query()->exists())->toBeFalse();
})->with([NotificationType::OrderChanged, NotificationType::DistributionLate, NotificationType::AccountSecurity]);

it('opens the notification center when the notification designates no screen', function () {
    PushTesting::notification(NotificationType::QuotasPublished, 'k1', url: null)->sendOnce([$this->user]);

    expect($this->push->payloads()[0]['url'])->toBe('/notifications');
});

it('sends to every device of the user and only to theirs', function () {
    $tablet = PushSubscription::factory()->create(['user_id' => $this->user->id]);
    $other = userWithRole(Role::Regional);
    PushSubscription::factory()->create(['user_id' => $other->id]);

    ($this->send)(NotificationType::OrderSubmitted, 'k1');

    expect($this->push->sent)->toHaveCount(1)
        ->and($this->push->sent[0]['endpoints'])->toBe([$this->device->endpoint, $tablet->endpoint]);
});

it('pushes nothing when the Admin turned push off, without VAPID keys or without a device', function (Closure $setup) {
    $setup->call($this);

    ($this->send)(NotificationType::OrderSubmitted, 'k1');

    // Le centre de notifications, lui, reçoit la notification (EF-NOT-01).
    expect(($this->stored)())->toBe(1)
        ->and($this->push->sent)->toBeEmpty()
        ->and(PushThrottle::query()->exists())->toBeFalse();
})->with([
    'push_enabled false' => fn () => function () {
        PushTesting::settings(['push_enabled' => false]);
    },
    'no VAPID keys' => fn () => function () {
        config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);
    },
    'no subscription' => fn () => function () {
        $this->device->delete();
    },
]);

it('drops the push during the quiet hours instead of deferring it', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 22:15', 'Africa/Algiers'));
    Queue::fake([FlushPush::class]);

    ($this->send)(NotificationType::QuotasPublished, 'k1');
    $this->travelTo(CarbonImmutable::parse('2026-10-09 06:00', 'Africa/Algiers'));
    ($this->send)(NotificationType::QuotasPublished, 'k2', 'Quotas du 09/10');

    // Abandonné (I-105) : le push de 06:00 part seul, immédiatement, sans rattrapage.
    expect(($this->stored)())->toBe(2)
        ->and($this->push->payloads())->toHaveCount(1)
        ->and($this->push->payloads()[0]['title'])->toBe('Quotas du 09/10');
    Queue::assertNotPushed(FlushPush::class);
});

it('knows the quiet hours in the business timezone, across midnight or within the day', function (?string $start, ?string $end, string $time, bool $quiet) {
    config(['ramymaak.settings.notifications.quiet_start' => $start, 'ramymaak.settings.notifications.quiet_end' => $end]);
    app(Settings::class)->flush();

    expect(app(QuietHours::class)->active(CarbonImmutable::parse("2026-10-08 {$time}", 'Africa/Algiers')))->toBe($quiet);
})->with([
    'before the evening start' => ['21:00', '06:00', '20:59', false],
    'evening start included' => ['21:00', '06:00', '21:00', true],
    'before midnight' => ['21:00', '06:00', '23:59', true],
    'after midnight' => ['21:00', '06:00', '00:00', true],
    'night' => ['21:00', '06:00', '03:30', true],
    'end excluded' => ['21:00', '06:00', '06:00', false],
    'day' => ['21:00', '06:00', '12:00', false],
    'within the day' => ['12:00', '14:00', '13:15', true],
    'within the day, end excluded' => ['12:00', '14:00', '14:00', false],
    'within the day, before' => ['12:00', '14:00', '11:59', false],
    'empty range' => ['08:00', '08:00', '08:00', false],
    'no start' => [null, '06:00', '03:00', false],
    'no end' => ['21:00', '', '23:00', false],
]);

it('reads the quiet hours of the Admin settings in Algiers time, not UTC', function () {
    PushTesting::settings(['quiet_start' => '12:00', 'quiet_end' => '14:00']);

    // 11:30 UTC = 12:30 à Alger.
    expect(app(QuietHours::class)->active(CarbonImmutable::parse('2026-10-08 11:30', 'UTC')))->toBeTrue()
        ->and(app(QuietHours::class)->active(CarbonImmutable::parse('2026-10-08 13:30', 'UTC')))->toBeFalse();
});

it('groups the orders submitted in a row: one push at once, then « 2 nouvelles commandes à valider » at the end of the window', function () {
    Queue::fake([FlushPush::class]);

    ($this->send)(NotificationType::OrderSubmitted, 'o1', 'Nouvelle commande — C0012 Superette El Baraka');
    $this->travel(1)->minutes();
    ($this->send)(NotificationType::OrderSubmitted, 'o2', 'Nouvelle commande — C0047 Alimentation Nour');
    $this->travel(1)->minutes();
    ($this->send)(NotificationType::OrderSubmitted, 'o3', 'Nouvelle commande — C0051 Épicerie du Port');

    expect(($this->stored)())->toBe(3)
        ->and($this->push->payloads())->toHaveCount(1)
        ->and($this->push->payloads()[0]['title'])->toBe('Nouvelle commande — C0012 Superette El Baraka');
    // Un seul envoi groupé programmé, à la fin de la fenêtre de 5 minutes.
    Queue::assertPushed(FlushPush::class, 1);
    Queue::assertPushed(FlushPush::class, fn (FlushPush $job) => $job->userId === $this->user->id
        && $job->group === 'ORDER_SUBMITTED'
        && CarbonImmutable::instance($job->delay)->eq(CarbonImmutable::parse('2026-10-08 09:35', 'Africa/Algiers')));
    expect(($this->throttle)('ORDER_SUBMITTED'))->pending->toBe(2);

    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:35', 'Africa/Algiers'));
    ($this->runFlush)();

    expect($this->push->payloads())->toHaveCount(2)
        ->and($this->push->payloads()[1])->toBe([
            'id' => null,
            'type' => 'ORDER_SUBMITTED',
            'code' => 'N-05',
            'title' => '2 nouvelles commandes à valider',
            'body' => 'Ouvrez les commandes reçues.',
            'url' => '/regional/commandes',
            'tag' => 'ORDER_SUBMITTED',
            'count' => 2,
        ])
        ->and($this->push->sent[1]['options']['urgency'])->toBe('normal');
    $throttle = ($this->throttle)('ORDER_SUBMITTED');
    expect($throttle->pending)->toBe(0)
        ->and($throttle->flush_at)->toBeNull()
        ->and($throttle->last_sent_at->eq(now()))->toBeTrue();

    // Tâche relancée : le compteur est à zéro, rien ne repart (§9.7).
    ($this->runFlush)();
    expect($this->push->payloads())->toHaveCount(2);
});

it('sends at most one grouped push per window, and the next push at once after a quiet window', function () {
    Queue::fake([FlushPush::class]);

    ($this->send)(NotificationType::OrderSubmitted, 'o1');
    $this->travel(2)->minutes();
    ($this->send)(NotificationType::OrderSubmitted, 'o2');
    $this->travel(1)->minutes();
    ($this->send)(NotificationType::OrderSubmitted, 'o3');
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:35', 'Africa/Algiers'));
    ($this->runFlush)();

    // 09:37 : dans la fenêtre du push groupé de 09:35 → compté, envoi programmé à 09:40.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:37', 'Africa/Algiers'));
    ($this->send)(NotificationType::OrderSubmitted, 'o4', 'Nouvelle commande — C0063 Supérette Amina');
    Queue::assertPushed(FlushPush::class, 2);
    Queue::assertPushed(FlushPush::class, fn (FlushPush $job) => CarbonImmutable::instance($job->delay)->eq(CarbonImmutable::parse('2026-10-08 09:40', 'Africa/Algiers')));

    // Une seule notification comptée : elle part seule, avec son texte et son écran.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:40', 'Africa/Algiers'));
    ($this->runFlush)();
    $fourth = DatabaseNotification::query()->whereMorphedTo('notifiable', $this->user)->where('data->title', 'Nouvelle commande — C0063 Supérette Amina')->sole();
    expect($this->push->payloads())->toHaveCount(3)
        ->and($this->push->payloads()[2])->toBe([
            'id' => $fourth->id,
            'type' => 'ORDER_SUBMITTED',
            'code' => 'N-05',
            'title' => 'Nouvelle commande — C0063 Supérette Amina',
            'body' => 'Texte de test.',
            'url' => '/notifications',
            'tag' => 'ORDER_SUBMITTED',
            'count' => 1,
        ]);

    // 09:45 : plus de 5 minutes après le dernier push → immédiat.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:45', 'Africa/Algiers'));
    ($this->send)(NotificationType::OrderSubmitted, 'o5', 'Nouvelle commande — C0070 Mini-marché');
    expect($this->push->payloads())->toHaveCount(4)
        ->and($this->push->payloads()[3]['title'])->toBe('Nouvelle commande — C0070 Mini-marché');
    Queue::assertPushed(FlushPush::class, 2);
});

it('groups other types as « n nouvelles notifications » with the title of the last one', function () {
    Queue::fake([FlushPush::class]);

    foreach (['Quotas du 08/10 modifiés — région Centre', 'Quotas du 08/10 modifiés — région Est', 'Quotas du 08/10 modifiés — région Ouest', 'Quotas du 08/10 modifiés — région Sud'] as $i => $title) {
        ($this->send)(NotificationType::QuotasUpdated, "q{$i}", $title);
        $this->travel(30)->seconds();
    }
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:35', 'Africa/Algiers'));
    ($this->runFlush)();

    expect($this->push->payloads())->toHaveCount(2)
        ->and($this->push->payloads()[1])->toBe([
            'id' => null,
            'type' => 'QUOTAS_UPDATED',
            'code' => 'N-02',
            'title' => '3 nouvelles notifications',
            'body' => 'Quotas du 08/10 modifiés — région Sud',
            'url' => '/notifications',
            'tag' => 'QUOTAS_UPDATED',
            'count' => 3,
        ]);
});

it('keeps the groups and the users apart', function () {
    Queue::fake([FlushPush::class]);
    $other = userWithRole(Role::Regional);
    PushSubscription::factory()->create(['user_id' => $other->id]);

    ($this->send)(NotificationType::OrderSubmitted, 'o1');
    ($this->send)(NotificationType::ValidationReminder, 'r1');
    ($this->send)(NotificationType::OrderSubmitted, 'o1', to: $other);

    expect($this->push->payloads($this->user->id))->toHaveCount(2)
        ->and($this->push->payloads($other->id))->toHaveCount(1);
    Queue::assertNotPushed(FlushPush::class);
});

it('drops the grouped push when the window ends in the quiet hours', function () {
    Queue::fake([FlushPush::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-08 20:57', 'Africa/Algiers'));

    ($this->send)(NotificationType::OrderSubmitted, 'o1');
    $this->travel(1)->minutes();
    ($this->send)(NotificationType::OrderSubmitted, 'o2');
    $this->travelTo(CarbonImmutable::parse('2026-10-08 21:02', 'Africa/Algiers'));
    ($this->runFlush)();

    expect($this->push->payloads())->toHaveCount(1)
        ->and(($this->throttle)('ORDER_SUBMITTED'))->pending->toBe(0)
        ->and(($this->throttle)('ORDER_SUBMITTED'))->flush_at->toBeNull();
});

it('ignores a grouped push job that runs ahead of its time', function () {
    Queue::fake([FlushPush::class]);

    ($this->send)(NotificationType::OrderSubmitted, 'o1');
    $this->travel(1)->minutes();
    ($this->send)(NotificationType::OrderSubmitted, 'o2');
    $this->travel(1)->minutes();
    ($this->send)(NotificationType::OrderSubmitted, 'o3');
    ($this->runFlush)();

    expect($this->push->payloads())->toHaveCount(1)
        ->and(($this->throttle)('ORDER_SUBMITTED'))->pending->toBe(2);
});

it('reschedules a grouped push whose job was lost', function () {
    Queue::fake([FlushPush::class]);

    ($this->send)(NotificationType::OrderSubmitted, 'o1');
    $this->travel(1)->minutes();
    ($this->send)(NotificationType::OrderSubmitted, 'o2');
    // La tâche de 09:35 ne s'est jamais exécutée (file arrêtée) ; 09:45 : nouvelle notification.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:45', 'Africa/Algiers'));
    ($this->send)(NotificationType::OrderSubmitted, 'o3');

    Queue::assertPushed(FlushPush::class, 2);
    Queue::assertPushed(FlushPush::class, fn (FlushPush $job) => CarbonImmutable::instance($job->delay)->eq(now()));
    ($this->runFlush)();
    expect($this->push->payloads())->toHaveCount(2)
        ->and($this->push->payloads()[1]['title'])->toBe('2 nouvelles commandes à valider');
});

it('removes expired devices and counts the other failures', function () {
    $this->device->forceFill(['failures' => 2])->save();
    $gone = PushSubscription::factory()->create(['user_id' => $this->user->id]);
    $unknown = PushSubscription::factory()->create(['user_id' => $this->user->id]);
    $failing = PushSubscription::factory()->create(['user_id' => $this->user->id, 'failures' => 1]);
    $offline = PushSubscription::factory()->create(['user_id' => $this->user->id]);
    $this->push->respondWith($gone->endpoint, 410)
        ->respondWith($unknown->endpoint, 404)
        ->respondWith($failing->endpoint, 500)
        ->respondWith($offline->endpoint, 0);

    (new DeliverPush($this->user->id, ['type' => 'ORDER_DECIDED', 'tag' => 'ORDER_DECIDED', 'title' => 'Titre']))->handle(app(PushTransport::class), app(QuietHours::class));

    expect(PushSubscription::query()->whereKey([$gone->id, $unknown->id])->exists())->toBeFalse()
        ->and($failing->fresh()->failures)->toBe(2)
        ->and($failing->fresh()->last_used_at)->toBeNull()
        ->and($offline->fresh()->failures)->toBe(1)
        ->and($this->device->fresh()->failures)->toBe(0)
        ->and($this->device->fresh()->last_used_at)->not->toBeNull();
});

it('does not deliver a queued push once push is turned off or the quiet hours have begun', function (Closure $setup) {
    $setup->call($this);

    (new DeliverPush($this->user->id, ['type' => 'ORDER_DECIDED', 'tag' => 'ORDER_DECIDED']))->handle(app(PushTransport::class), app(QuietHours::class));

    expect($this->push->sent)->toBeEmpty();
})->with([
    'push_enabled false' => fn () => function () {
        PushTesting::settings(['push_enabled' => false]);
    },
    'quiet hours' => fn () => function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 21:30', 'Africa/Algiers'));
    },
]);

it('never prevents the notification from being recorded when the push fails', function () {
    app()->instance(PushTransport::class, new class implements PushTransport
    {
        public function send(iterable $subscriptions, string $payload, array $options): array
        {
            throw new RuntimeException('Service push injoignable');
        }
    });
    $second = userWithRole(Role::Regional);
    PushSubscription::factory()->create(['user_id' => $second->id]);

    $sent = PushTesting::notification(NotificationType::OrderSubmitted, 'o1')->sendOnce([$this->user, $second]);

    expect($sent)->toBe(2)
        ->and(($this->stored)())->toBe(1)
        ->and(($this->stored)($second))->toBe(1);
});

it('does not push a notification twice when it is sent again', function () {
    ($this->send)(NotificationType::OrderDecided, 'd1');
    ($this->send)(NotificationType::OrderDecided, 'd1');

    expect(($this->stored)())->toBe(1)
        ->and($this->push->sent)->toHaveCount(1);
});
