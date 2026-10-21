<?php

use App\Domain\Access\Role;
use App\Domain\Days\CutoffKind;
use App\Domain\Days\DayCalendar;
use App\Domain\Quotas\QuotaBook;
use App\Events\CutoffExtended;
use App\Listeners\NotifyCutoffExtended;
use App\Models\Client;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionDay;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// Prolongation de l'heure limite de commande annoncée aux clients actifs de
// la région quand la répartition est envoyée (RG-A05, I-59 ; contrat du
// lot 5, §5), en plus des régionaux (lot 3).

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:30 à Alger : commande jusqu'à 13:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    $this->titular = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['region_id' => $this->centre->id, 'user_id' => $this->titular->id]);

    $this->a = Client::factory()->create(['code' => 'C0012', 'region_id' => $this->centre->id]);
    $this->accountA = userWithRole(Role::Client, ['client_id' => $this->a->id]);
    $this->secondA = userWithRole(Role::Client, ['client_id' => $this->a->id]);
    // Client rattaché au Centre à partir du 08/10 (région effective, RG-S04).
    $this->movedIn = Client::factory()->create(['region_id' => $this->est->id, 'next_region_id' => $this->centre->id, 'region_change_on' => '2026-10-08']);
    $this->movedInAccount = userWithRole(Role::Client, ['client_id' => $this->movedIn->id]);
    $this->orange = Product::factory()->create(['sku' => 'P0042']);

    $this->calendar = app(DayCalendar::class);
    // Quotas publiés (N-01) et répartition envoyée (N-03) ; une minute passe ensuite.
    $this->regionDay = openOrderingDay('2026-10-08', $this->centre, [[$this->orange, 500]]);
    $this->travel(1)->minutes();
    $this->extend = function (CutoffKind $kind, string $until, ?RegionDay $regionDay = null): void {
        $this->calendar->extendCutoff($regionDay ?? $this->regionDay, $kind, $until, $this->adv);
        $this->travel(1)->minutes();
    };
    $this->event = fn (CutoffKind $kind = CutoffKind::Ordering) => new CutoffExtended($this->regionDay->id, $kind, $this->adv->id);
    $this->noticesOf = fn (User $user) => DatabaseNotification::query()
        ->whereMorphedTo('notifiable', $user)
        ->where('type', 'CUTOFF_EXTENDED')
        ->orderBy('created_at')
        ->get();
    $this->clientNotices = fn () => DatabaseNotification::query()->where('type', 'CUTOFF_EXTENDED')->where('data->url', '/client');
    // Type et données enregistrées, clés triées : MySQL réordonne les clés JSON.
    $this->contentOf = fn (DatabaseNotification $notification) => [$notification->type, collect($notification->data)->sortKeys()->all()];
    $this->content = fn (string $type, array $data) => [$type, collect($data)->sortKeys()->all()];
});

it('tells the active client accounts of the active clients of the region that the ordering cutoff is extended (I-59)', function () {
    // Ne reçoivent rien : client bloqué, client d'une autre région, client
    // parti du Centre le 08/10, compte désactivé, compte passé à un autre rôle.
    $blocked = Client::factory()->blocked()->create(['region_id' => $this->centre->id]);
    $blockedAccount = userWithRole(Role::Client, ['client_id' => $blocked->id]);
    $elsewhere = Client::factory()->create(['region_id' => $this->est->id]);
    $elsewhereAccount = userWithRole(Role::Client, ['client_id' => $elsewhere->id]);
    $movedOut = Client::factory()->create(['region_id' => $this->centre->id, 'next_region_id' => $this->est->id, 'region_change_on' => '2026-10-08']);
    $movedOutAccount = userWithRole(Role::Client, ['client_id' => $movedOut->id]);
    $inactive = userWithRole(Role::Client, ['client_id' => $this->a->id, 'is_active' => false]);
    $former = userWithRole(Role::Adv, ['client_id' => $this->a->id]);

    ($this->extend)(CutoffKind::Ordering, '13:30');

    $expected = ($this->content)('CUTOFF_EXTENDED', [
        'type' => 'CUTOFF_EXTENDED',
        'code' => null,
        'title' => 'Heure limite de commande prolongée',
        'body' => 'Commandez jusqu\'à 13:30.',
        'url' => '/client',
    ]);
    foreach ([$this->accountA, $this->secondA, $this->movedInAccount] as $recipient) {
        $notices = ($this->noticesOf)($recipient);
        expect($notices)->toHaveCount(1)
            ->and(($this->contentOf)($notices[0]))->toBe($expected)
            ->and($notices[0]->read_at)->toBeNull();
    }
    foreach ([$blockedAccount, $elsewhereAccount, $movedOutAccount, $inactive, $former, $this->adv] as $nobody) {
        expect(($this->noticesOf)($nobody))->toBeEmpty();
    }

    // Les régionaux gardent leur notification (lot 3), inchangée.
    expect(($this->contentOf)(($this->noticesOf)($this->titular)->sole()))->toBe(($this->content)('CUTOFF_EXTENDED', [
        'type' => 'CUTOFF_EXTENDED',
        'code' => null,
        'title' => 'Heure limite prolongée — région Centre',
        'body' => 'Commande jusqu\'à 13:30.',
        'url' => '/regional/quotas?day=2026-10-08',
    ]))->and(($this->clientNotices)()->count())->toBe(3);

    // Dans le centre de notifications du client, au format du contrat.
    $this->actingAs($this->accountA)
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.0', [
            'id' => ($this->noticesOf)($this->accountA)->sole()->id,
            'type' => 'CUTOFF_EXTENDED',
            'code' => null,
            'title' => 'Heure limite de commande prolongée',
            'body' => 'Commandez jusqu\'à 13:30.',
            'url' => '/client',
            'read_at' => null,
            'created_at' => '2026-10-08T08:31:00+00:00',
        ]);
});

it('tells the clients nothing before the distribution is sent, nor for the distribution cutoff', function () {
    // Est : quotas publiés, répartition pas encore envoyée.
    $estTitular = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['region_id' => $this->est->id, 'user_id' => $estTitular->id]);
    $estClient = Client::factory()->create(['region_id' => $this->est->id]);
    $estAccount = userWithRole(Role::Client, ['client_id' => $estClient->id]);
    $quotas = app(QuotaBook::class);
    $quotas->saveDrafts($this->regionDay->businessDay, [['region_id' => $this->est->id, 'product_id' => $this->orange->id, 'quantity' => 200]], $this->adv);
    $quotas->publish($this->regionDay->businessDay, [$this->est->id], $this->adv);
    $estDay = RegionDay::query()->where('business_day_id', $this->regionDay->business_day_id)->where('region_id', $this->est->id)->sole();

    ($this->extend)(CutoffKind::Ordering, '13:30', $estDay);
    // Centre : prolongation de la répartition, qui ne concerne pas les clients.
    ($this->extend)(CutoffKind::Distribution, '11:00');

    expect(($this->noticesOf)($estTitular)->sole()->data['body'])->toBe('Commande jusqu\'à 13:30.')
        ->and(($this->noticesOf)($this->titular)->sole()->data['body'])->toBe('Répartition jusqu\'à 11:00.')
        ->and(($this->noticesOf)($estAccount))->toBeEmpty()
        ->and(($this->clientNotices)()->count())->toBe(0);
});

it('never sends the same notice twice when the event or the job is replayed, but announces each new cutoff (§9.7)', function () {
    ($this->extend)(CutoffKind::Ordering, '13:30');

    // Événement rediffusé, tâche relancée : rien de plus.
    event(($this->event)());
    app(NotifyCutoffExtended::class)->handle(($this->event)());
    expect(($this->noticesOf)($this->accountA))->toHaveCount(1)
        ->and(($this->clientNotices)()->count())->toBe(3);

    // Seconde prolongation : une nouvelle notification, avec la nouvelle heure.
    ($this->extend)(CutoffKind::Ordering, '14:00');
    expect(($this->noticesOf)($this->accountA)->pluck('data.body')->all())->toBe(['Commandez jusqu\'à 13:30.', 'Commandez jusqu\'à 14:00.'])
        ->and(($this->noticesOf)($this->titular))->toHaveCount(2)
        ->and(($this->clientNotices)()->count())->toBe(6);
});

it('reads the client status and the region day when the job runs', function () {
    $event = ($this->event)();
    // Le client est bloqué avant l'exécution de la tâche : il n'est pas prévenu.
    $this->a->forceFill(['status' => 'BLOCKED'])->save();
    $this->regionDay->forceFill(['ordering_cutoff_override_at' => CarbonImmutable::parse('2026-10-08 13:45', 'Africa/Algiers')->utc()])->save();

    app(NotifyCutoffExtended::class)->handle($event);

    expect(($this->noticesOf)($this->accountA))->toBeEmpty()
        // Heure applicable au moment de l'envoi.
        ->and(($this->noticesOf)($this->movedInAccount)->sole()->data['body'])->toBe('Commandez jusqu\'à 13:45.');

    // Région clôturée avant l'exécution de la tâche : plus rien aux clients.
    DB::table('region_days')->where('id', $this->regionDay->id)->update(['status' => 'CLOSED']);
    $this->regionDay->forceFill(['ordering_cutoff_override_at' => CarbonImmutable::parse('2026-10-08 14:00', 'Africa/Algiers')->utc()])->save();
    app(NotifyCutoffExtended::class)->handle($event);
    expect(($this->clientNotices)()->count())->toBe(1);
});

it('sends the notices from the background job of the extension (EF-NOT-06)', function () {
    Queue::fake();

    ($this->extend)(CutoffKind::Ordering, '13:30');

    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === NotifyCutoffExtended::class
        && $job->data[0] instanceof CutoffExtended
        && $job->data[0]->kind === CutoffKind::Ordering
        && $job->tries === 3);
    Queue::assertPushed(CallQueuedListener::class, 1);
    // Rien n'est envoyé pendant la requête de l'ADV.
    expect(DatabaseNotification::query()->where('type', 'CUTOFF_EXTENDED')->count())->toBe(0);
});

it('tells the 150 clients of a region without one query per recipient beyond the notification itself (EF-NOT-06)', function () {
    $clients = Client::factory()->count(147)->create(['region_id' => $this->centre->id]);
    foreach ($clients as $client) {
        userWithRole(Role::Client, ['client_id' => $client->id]);
    }
    $this->regionDay->forceFill(['ordering_cutoff_override_at' => CarbonImmutable::parse('2026-10-08 13:30', 'Africa/Algiers')->utc()])->save();
    // Diffusions laissées en file : seule la tâche de notification est mesurée.
    Queue::fake();

    DB::enableQueryLog();
    app(NotifyCutoffExtended::class)->handle(($this->event)());
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // 150 comptes clients et le titulaire : contrôle de la clé d'unicité et
    // enregistrement par destinataire ; journée, régionaux, comptes par paquets.
    expect(($this->clientNotices)()->count())->toBe(150)
        ->and($queries)->toBeLessThanOrEqual(2 * 151 + 8);
});
