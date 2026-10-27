<?php

use App\Domain\Access\Role;
use App\Domain\Days\CutoffKind;
use App\Domain\Days\DayCalendar;
use App\Domain\Quotas\QuotaBook;
use App\Domain\Reminders\Reminder;
use App\Domain\Reminders\Reminders;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionDay;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;

// Rappel N-11 aux comptes des clients notifiés sans commande comptée
// (contrat du lot 8, §3, I-88, I-107, EF-NOT-04).

beforeEach(function () {
    // Jeudi 8 octobre 2026, 08:30 à Alger : commande jusqu'à 13:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 08:30', 'Africa/Algiers'));
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    $this->regional = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['region_id' => $this->centre->id, 'user_id' => $this->regional->id]);
    $this->orange = Product::factory()->create(['sku' => 'P0042']);

    // A : deux comptes, sans commande ; B : commande envoyée ; G : commande
    // validée ; E : commande rejetée seulement (sans commande, I-88) ;
    // D : compte qui a coupé les rappels ; C : client bloqué (non notifié).
    $client = fn (string $code, array $state = []) => Client::factory()->create(['code' => $code, 'region_id' => $this->centre->id, ...$state]);
    $this->a = $client('C0012');
    $this->b = $client('C0047');
    $this->g = $client('C0051');
    $this->e = $client('C0060');
    $this->d = $client('C0070');
    $this->c = Client::factory()->blocked()->create(['code' => 'C0080', 'region_id' => $this->centre->id]);
    $this->accountA = userWithRole(Role::Client, ['client_id' => $this->a->id]);
    $this->secondA = userWithRole(Role::Client, ['client_id' => $this->a->id]);
    $this->inactiveA = userWithRole(Role::Client, ['client_id' => $this->a->id, 'is_active' => false]);
    $this->formerA = userWithRole(Role::Adv, ['client_id' => $this->a->id]);
    $this->accountB = userWithRole(Role::Client, ['client_id' => $this->b->id]);
    $this->accountG = userWithRole(Role::Client, ['client_id' => $this->g->id]);
    $this->accountE = userWithRole(Role::Client, ['client_id' => $this->e->id]);
    $this->accountD = userWithRole(Role::Client, ['client_id' => $this->d->id]);
    $this->accountD->forceFill(['reminders_enabled' => false])->save();
    $this->accountC = userWithRole(Role::Client, ['client_id' => $this->c->id]);
    $this->estClient = Client::factory()->create(['code' => 'C0099', 'region_id' => $this->est->id]);
    $this->estAccount = userWithRole(Role::Client, ['client_id' => $this->estClient->id]);

    // Quotas publiés et répartition envoyée pour le Centre (N-03).
    $this->regionDay = openOrderingDay('2026-10-08', $this->centre, [[$this->orange, 500]]);
    $order = fn (Client $client) => Order::factory()->state(['business_day_id' => $this->regionDay->business_day_id, 'client_id' => $client->id]);
    $order($this->b)->create();
    $order($this->g)->validated()->create();
    $order($this->e)->rejected()->create();

    $this->at = function (string $time): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 '.$time, 'Africa/Algiers'));
        $this->artisan('ramymaak:send-reminders')->assertSuccessful();
    };
    $this->received = fn (User $user) => DatabaseNotification::query()
        ->whereMorphedTo('notifiable', $user)
        ->where('type', 'ORDERING_REMINDER')
        ->orderBy('created_at')
        ->get();
});

it('reminds the notified clients without a counted order in the hour before the ordering cutoff, once', function () {
    ($this->at)('11:59');
    expect(($this->received)($this->accountA))->toBeEmpty();

    ($this->at)('12:00');
    ($this->at)('12:30');
    ($this->at)('12:59');

    foreach ([$this->accountA, $this->secondA, $this->accountE] as $recipient) {
        $notifications = ($this->received)($recipient);
        expect($notifications)->toHaveCount(1)
            ->and(collect($notifications[0]->data)->sortKeys()->all())->toBe(collect([
                'type' => 'ORDERING_REMINDER',
                'code' => 'N-11',
                'title' => 'Rappel : commande avant 13:00',
                'body' => 'Vous n\'avez pas encore commandé pour le 08/10.',
                'url' => '/client',
            ])->sortKeys()->all())
            ->and($notifications[0]->created_at->toIso8601String())->toBe('2026-10-08T11:00:00+00:00');
    }
    // Ont commandé (envoyée, validée), rappels coupés, client bloqué, compte
    // désactivé ou passé à un autre rôle, autre région sans répartition, régional.
    foreach ([$this->accountB, $this->accountG, $this->accountD, $this->accountC, $this->inactiveA, $this->formerA, $this->estAccount, $this->regional] as $nobody) {
        expect(($this->received)($nobody))->toBeEmpty();
    }
});

it('sends nothing at or after the ordering cutoff', function () {
    ($this->at)('13:00');
    ($this->at)('14:00');

    expect(($this->received)($this->accountA))->toBeEmpty();
});

it('does not remind a client who ordered before the reminder', function () {
    Order::factory()->create(['business_day_id' => $this->regionDay->business_day_id, 'client_id' => $this->a->id]);

    ($this->at)('12:00');

    expect(($this->received)($this->accountA))->toBeEmpty()
        ->and(($this->received)($this->accountE))->toHaveCount(1);
});

it('needs the distribution of the region to be sent', function () {
    // Est : quotas publiés, répartition pas encore envoyée.
    $quotas = app(QuotaBook::class);
    $day = $this->regionDay->businessDay;
    $quotas->saveDrafts($day, [['region_id' => $this->est->id, 'product_id' => $this->orange->id, 'quantity' => 200]], $this->adv);
    $quotas->publish($day, [$this->est->id], $this->adv);

    ($this->at)('12:00');

    $estDay = RegionDay::query()->with('businessDay')->where('business_day_id', $day->id)->where('region_id', $this->est->id)->sole();
    expect(($this->received)($this->estAccount))->toBeEmpty()
        ->and(app(Reminders::class)->isDue($estDay, Reminder::Ordering, now()))->toBeFalse()
        ->and(app(Reminders::class)->isDue($this->regionDay, Reminder::Ordering, now()))->toBeTrue();
});

it('reminds again before the extended ordering cutoff (I-107)', function () {
    ($this->at)('12:00');

    $this->travelTo(CarbonImmutable::parse('2026-10-08 12:10', 'Africa/Algiers'));
    app(DayCalendar::class)->extendCutoff($this->regionDay, CutoffKind::Ordering, '13:30', $this->adv);
    ($this->at)('12:15');
    expect(($this->received)($this->accountA))->toHaveCount(1);

    ($this->at)('12:30');
    ($this->at)('13:15');

    expect(($this->received)($this->accountA)->pluck('data.title')->all())->toBe([
        'Rappel : commande avant 13:00',
        'Rappel : commande avant 13:30',
    ]);
});
