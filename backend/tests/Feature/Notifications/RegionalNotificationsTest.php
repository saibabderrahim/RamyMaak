<?php

use App\Domain\Access\Role;
use App\Domain\Days\CutoffKind;
use App\Domain\Days\DayCalendar;
use App\Domain\Quotas\QuotaBook;
use App\Events\CutoffExtended;
use App\Events\QuotasPublished;
use App\Listeners\NotifyCutoffExtended;
use App\Listeners\NotifyQuotasPublished;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionDay;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Queue;

// Notifications N-01, N-02 et prolongation d'heure limite aux régionaux de la
// région (§7.8, RG-A05, EF-NOT-06 ; contrat du lot 3, §6).

beforeEach(function () {
    // Jeudi 8 octobre 2026, 07:30 à Alger : répartition jusqu'à 10:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 07:30', 'Africa/Algiers'));
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);

    // Centre : un titulaire et un suppléant actifs le 08/10.
    $this->titular = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['region_id' => $this->centre->id, 'user_id' => $this->titular->id]);
    $this->deputy = userWithRole(Role::Regional);
    RegionAssignment::factory()->deputy('2026-10-07', '2026-10-15')->create(['region_id' => $this->centre->id, 'user_id' => $this->deputy->id]);

    $this->products = Product::factory()->count(3)->create();
    $this->calendar = app(DayCalendar::class);
    $this->book = app(QuotaBook::class);
    $this->day = $this->calendar->open(BusinessClock::parseDay('2026-10-08'), $this->adv);

    // Brouillons d'une région (quantités dans l'ordre des produits) puis
    // publication ; une minute passe ensuite (notifications ordonnées).
    $this->publish = function (Region $region, array $quantities): void {
        $cells = [];
        foreach ($quantities as $index => $quantity) {
            $cells[] = ['region_id' => $region->id, 'product_id' => $this->products[$index]->id, 'quantity' => $quantity];
        }
        $this->book->saveDrafts($this->day, $cells, $this->adv);
        $this->book->publish($this->day, [$region->id], $this->adv);
        $this->travel(1)->minutes();
    };
    $this->extend = function (CutoffKind $kind, string $until): void {
        $this->calendar->extendCutoff(($this->regionDay)($this->centre), $kind, $until, $this->adv);
        $this->travel(1)->minutes();
    };
    $this->regionDay = fn (Region $region) => RegionDay::query()->where('business_day_id', $this->day->id)->where('region_id', $region->id)->sole();
    $this->notificationsOf = fn (User $user) => DatabaseNotification::query()->whereMorphedTo('notifiable', $user)->orderBy('created_at')->get();
    // Type et données enregistrées, clés triées : MySQL réordonne les clés JSON.
    $this->contentOf = fn (DatabaseNotification $notification) => [$notification->type, collect($notification->data)->sortKeys()->all()];
    $this->content = fn (string $type, array $data) => [$type, collect($data)->sortKeys()->all()];
});

it('sends N-01 to the titular and the active deputy of the region only', function () {
    // Ne reçoivent rien : suppléance révoquée, terminée la veille ou à venir,
    // compte désactivé, régional d'une autre région, ADV.
    $revoked = userWithRole(Role::Regional);
    RegionAssignment::factory()->deputy()->revoked()->create(['region_id' => $this->centre->id, 'user_id' => $revoked->id]);
    $expired = userWithRole(Role::Regional);
    RegionAssignment::factory()->deputy('2026-10-01', '2026-10-07')->create(['region_id' => $this->centre->id, 'user_id' => $expired->id]);
    $upcoming = userWithRole(Role::Regional);
    RegionAssignment::factory()->deputy('2026-10-09', '2026-10-15')->create(['region_id' => $this->centre->id, 'user_id' => $upcoming->id]);
    $inactive = userWithRole(Role::Regional, ['is_active' => false]);
    RegionAssignment::factory()->deputy()->create(['region_id' => $this->centre->id, 'user_id' => $inactive->id]);
    $other = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['region_id' => $this->est->id, 'user_id' => $other->id]);

    ($this->publish)($this->centre, [500, 300]);

    $expected = ($this->content)('QUOTAS_PUBLISHED', [
        'type' => 'QUOTAS_PUBLISHED',
        'code' => 'N-01',
        'title' => 'Quotas du 08/10 reçus — région Centre',
        'body' => 'À répartir avant 10:00.',
        'url' => '/regional/quotas?day=2026-10-08',
    ]);
    foreach ([$this->titular, $this->deputy] as $recipient) {
        $notifications = ($this->notificationsOf)($recipient);
        expect($notifications)->toHaveCount(1)
            ->and(($this->contentOf)($notifications[0]))->toBe($expected)
            ->and($notifications[0]->read_at)->toBeNull();
    }
    foreach ([$revoked, $expired, $upcoming, $inactive, $other, $this->adv] as $nobody) {
        expect(($this->notificationsOf)($nobody))->toBeEmpty();
    }
});

it('announces the distribution cutoff applicable to the region, extension included (RG-A05)', function () {
    ($this->extend)(CutoffKind::Distribution, '11:15');

    ($this->publish)($this->centre, [500]);

    $n01 = ($this->notificationsOf)($this->titular)->firstWhere('type', 'QUOTAS_PUBLISHED');
    expect($n01->data['body'])->toBe('À répartir avant 11:15.');
});

it('sends N-02 with the number of changed quotas on each later publication', function () {
    ($this->publish)($this->centre, [500, 300, 200]);
    ($this->publish)($this->centre, [450, 250, 0]);
    ($this->publish)($this->centre, [400]);

    $notifications = ($this->notificationsOf)($this->titular);
    expect($notifications->pluck('type')->all())->toBe(['QUOTAS_PUBLISHED', 'QUOTAS_UPDATED', 'QUOTAS_UPDATED'])
        ->and(($this->contentOf)($notifications[1]))->toBe(($this->content)('QUOTAS_UPDATED', [
            'type' => 'QUOTAS_UPDATED',
            'code' => 'N-02',
            'title' => 'Quotas du 08/10 modifiés — région Centre',
            'body' => 'L\'ADV a modifié 3 quotas.',
            'url' => '/regional/quotas?day=2026-10-08',
        ]))
        // Singulier pour un seul quota modifié.
        ->and($notifications[2]->data['body'])->toBe('L\'ADV a modifié 1 quota.')
        ->and(($this->notificationsOf)($this->deputy))->toHaveCount(3);
});

it('notifies the regionals of the region when a cutoff is extended (RG-A05)', function () {
    $other = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['region_id' => $this->est->id, 'user_id' => $other->id]);

    ($this->extend)(CutoffKind::Distribution, '11:15');
    ($this->extend)(CutoffKind::Ordering, '13:30');
    // Seconde prolongation de la répartition : une nouvelle notification.
    ($this->extend)(CutoffKind::Distribution, '11:45');

    $notifications = ($this->notificationsOf)($this->deputy);
    expect($notifications)->toHaveCount(3)
        ->and(($this->contentOf)($notifications[0]))->toBe(($this->content)('CUTOFF_EXTENDED', [
            'type' => 'CUTOFF_EXTENDED',
            'code' => null,
            'title' => 'Heure limite prolongée — région Centre',
            'body' => 'Répartition jusqu\'à 11:15.',
            'url' => '/regional/quotas?day=2026-10-08',
        ]))
        ->and($notifications->pluck('data.body')->all())->toBe(['Répartition jusqu\'à 11:15.', 'Commande jusqu\'à 13:30.', 'Répartition jusqu\'à 11:45.'])
        ->and(($this->notificationsOf)($this->titular))->toHaveCount(3)
        ->and(($this->notificationsOf)($other))->toBeEmpty();
});

it('never sends the same notification twice when an event or a job is replayed (§9.7)', function () {
    ($this->publish)($this->centre, [500]);
    $regionDay = ($this->regionDay)($this->centre);

    // Événement rediffusé, tâche relancée : rien de plus.
    event(new QuotasPublished($regionDay->id, 1, true, 1, $this->adv->id));
    app(NotifyQuotasPublished::class)->handle(new QuotasPublished($regionDay->id, 1, true, 1, $this->adv->id));
    expect(($this->notificationsOf)($this->titular))->toHaveCount(1)
        ->and(($this->notificationsOf)($this->deputy))->toHaveCount(1);

    // Une nouvelle version, elle, se notifie, une seule fois.
    event(new QuotasPublished($regionDay->id, 2, false, 2, $this->adv->id));
    event(new QuotasPublished($regionDay->id, 2, false, 2, $this->adv->id));
    expect(($this->notificationsOf)($this->titular)->pluck('type')->all())->toBe(['QUOTAS_PUBLISHED', 'QUOTAS_UPDATED']);

    ($this->extend)(CutoffKind::Ordering, '14:00');
    event(new CutoffExtended($regionDay->id, CutoffKind::Ordering, $this->adv->id));
    app(NotifyCutoffExtended::class)->handle(new CutoffExtended($regionDay->id, CutoffKind::Ordering, $this->adv->id));
    expect(($this->notificationsOf)($this->titular)->where('type', 'CUTOFF_EXTENDED'))->toHaveCount(1)
        ->and(DatabaseNotification::query()->count())->toBe(6);
});

it('sends the notifications from a background job (EF-NOT-06)', function () {
    Queue::fake();

    ($this->publish)($this->centre, [500]);
    ($this->extend)(CutoffKind::Distribution, '11:00');

    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === NotifyQuotasPublished::class
        && $job->data[0] instanceof QuotasPublished
        && $job->data[0]->first
        // Relancée après un échec technique, sans double envoi.
        && $job->tries === 3);
    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === NotifyCutoffExtended::class
        && $job->data[0]->kind === CutoffKind::Distribution);
    // Rien n'est envoyé pendant la requête de l'ADV.
    expect(DatabaseNotification::query()->count())->toBe(0);
});

it('ignores an event whose region day no longer exists', function () {
    app(NotifyQuotasPublished::class)->handle(new QuotasPublished(999_999, 1, true, 1, null));
    app(NotifyCutoffExtended::class)->handle(new CutoffExtended(999_999, CutoffKind::Distribution, null));

    expect(DatabaseNotification::query()->count())->toBe(0);
});

it('never notifies an account whose role is no longer regional, even if its assignment is still open (§7.8)', function () {
    $this->titular->syncRoles([Role::Adv->value]);
    $this->deputy->syncRoles([Role::Client->value]);

    ($this->publish)($this->centre, [100]);
    ($this->extend)(CutoffKind::Distribution, '11:00');

    expect(($this->notificationsOf)($this->titular))->toHaveCount(0)
        ->and(($this->notificationsOf)($this->deputy))->toHaveCount(0);
});
