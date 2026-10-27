<?php

use App\Domain\Access\Role;
use App\Domain\Days\CutoffKind;
use App\Domain\Days\DayCalendar;
use App\Domain\Orders\OrderStatus;
use App\Models\Client;
use App\Models\Order;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionDay;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;

// Rappel N-12 aux régionaux : commandes encore envoyées à l'approche de
// l'heure limite de validation (contrat du lot 8, §3, I-71, I-107, EF-NOT-04).

beforeEach(function () {
    // Jeudi 8 octobre 2026, 08:30 à Alger : validation jusqu'à 15:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 08:30', 'Africa/Algiers'));
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    $this->titular = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['region_id' => $this->centre->id, 'user_id' => $this->titular->id]);
    $this->deputy = userWithRole(Role::Regional);
    RegionAssignment::factory()->deputy('2026-10-07', '2026-10-15')->create(['region_id' => $this->centre->id, 'user_id' => $this->deputy->id]);
    $this->muted = userWithRole(Role::Regional);
    $this->muted->forceFill(['reminders_enabled' => false])->save();
    RegionAssignment::factory()->create(['region_id' => $this->centre->id, 'user_id' => $this->muted->id]);
    $this->estRegional = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['region_id' => $this->est->id, 'user_id' => $this->estRegional->id]);

    $this->calendar = app(DayCalendar::class);
    $this->day = $this->calendar->open(BusinessClock::parseDay('2026-10-08'), $this->adv);
    $order = fn (Region $client, ?Region $frozen = null) => Order::factory()->state([
        'business_day_id' => $this->day->id,
        'client_id' => Client::factory()->create(['region_id' => $client->id])->id,
        'region_id' => ($frozen ?? $client)->id,
    ]);
    // Centre : deux commandes envoyées, dont une d'un client passé à l'Est
    // depuis (région figée à l'envoi), et une validée. Est : une envoyée.
    $this->first = $order($this->centre)->create();
    $order($this->est, frozen: $this->centre)->create();
    $order($this->centre)->validated()->create();
    $order($this->est)->create();

    $this->at = function (string $time): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 '.$time, 'Africa/Algiers'));
        $this->artisan('ramymaak:send-reminders')->assertSuccessful();
    };
    $this->received = fn (User $user) => DatabaseNotification::query()
        ->whereMorphedTo('notifiable', $user)
        ->where('type', 'VALIDATION_REMINDER')
        ->orderBy('created_at')
        ->get();
});

it('reminds the regionals of the submitted orders in the 30 minutes before the validation cutoff, once', function () {
    ($this->at)('14:29');
    expect(($this->received)($this->titular))->toBeEmpty();

    ($this->at)('14:30');
    ($this->at)('14:59');

    foreach ([$this->titular, $this->deputy] as $regional) {
        $notifications = ($this->received)($regional);
        expect($notifications)->toHaveCount(1)
            ->and(collect($notifications[0]->data)->sortKeys()->all())->toBe(collect([
                'type' => 'VALIDATION_REMINDER',
                'code' => 'N-12',
                'title' => 'Rappel : 2 commandes à valider',
                'body' => 'Centre : validez avant 15:00.',
                'url' => '/regional/commandes?day=2026-10-08',
            ])->sortKeys()->all());
    }
    $est = ($this->received)($this->estRegional);
    expect($est)->toHaveCount(1)
        ->and($est[0]->data['title'])->toBe('Rappel : 1 commande à valider')
        ->and($est[0]->data['body'])->toBe('Est : validez avant 15:00.')
        // Rappels coupés (EF-NOT-04).
        ->and(($this->received)($this->muted))->toBeEmpty()
        ->and(($this->received)($this->adv))->toBeEmpty();
});

it('sends nothing at or after the validation cutoff', function () {
    ($this->at)('15:00');
    ($this->at)('16:00');

    expect(($this->received)($this->titular))->toBeEmpty()
        ->and(($this->received)($this->estRegional))->toBeEmpty();
});

it('needs submitted orders in the region that day', function () {
    Order::query()->where('region_id', $this->centre->id)->update(['status' => OrderStatus::Validated->value]);

    ($this->at)('14:30');

    expect(($this->received)($this->titular))->toBeEmpty()
        ->and(($this->received)($this->estRegional))->toHaveCount(1);
});

it('reminds again before the extended validation cutoff, with the orders left (I-71, I-107)', function () {
    ($this->at)('14:30');

    $this->travelTo(CarbonImmutable::parse('2026-10-08 14:40', 'Africa/Algiers'));
    $regionDay = RegionDay::query()->where('business_day_id', $this->day->id)->where('region_id', $this->centre->id)->sole();
    $this->calendar->extendCutoff($regionDay, CutoffKind::Validation, '15:30', $this->adv);
    $this->first->forceFill(['status' => OrderStatus::Validated->value])->save();
    ($this->at)('14:45');
    expect(($this->received)($this->titular))->toHaveCount(1);

    ($this->at)('15:00');

    $notifications = ($this->received)($this->titular);
    expect($notifications)->toHaveCount(2)
        ->and($notifications[1]->data['title'])->toBe('Rappel : 1 commande à valider')
        ->and($notifications[1]->data['body'])->toBe('Centre : validez avant 15:30.')
        // Est : pas de prolongation, plus rien après 15:00.
        ->and(($this->received)($this->estRegional))->toHaveCount(1);
});
