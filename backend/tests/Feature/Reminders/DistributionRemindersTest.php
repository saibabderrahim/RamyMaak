<?php

use App\Domain\Access\Role;
use App\Domain\Days\CutoffKind;
use App\Domain\Days\DayCalendar;
use App\Domain\Limits\LimitBook;
use App\Domain\Quotas\QuotaBook;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionDay;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;

// Rappel N-10 aux régionaux et alerte N-13 à l'ADV et à l'Admin : répartition
// pas encore envoyée (contrat du lot 8, §3, I-107, EF-NOT-04).

beforeEach(function () {
    // Jeudi 8 octobre 2026, 07:30 à Alger : répartition jusqu'à 10:00, clôture à 23:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 07:30', 'Africa/Algiers'));
    // ADV jamais affecté : toutes les régions (RG-S05).
    $this->adv = userWithRole(Role::Adv);
    $this->admin = admin();
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    // Centre : un titulaire et un suppléant actifs le 08/10.
    $this->titular = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['region_id' => $this->centre->id, 'user_id' => $this->titular->id]);
    $this->deputy = userWithRole(Role::Regional);
    RegionAssignment::factory()->deputy('2026-10-07', '2026-10-15')->create(['region_id' => $this->centre->id, 'user_id' => $this->deputy->id]);
    $this->product = Product::factory()->create(['sku' => 'P0042']);

    $this->calendar = app(DayCalendar::class);
    $this->day = $this->calendar->open(BusinessClock::parseDay('2026-10-08'), $this->adv);
    $this->regionDay = fn (Region $region): RegionDay => RegionDay::query()
        ->where('business_day_id', $this->day->id)
        ->where('region_id', $region->id)
        ->sole();
    // Quotas publiés par l'ADV (N-01 aux régionaux).
    $this->publishQuotas = function (Region ...$regions): void {
        $quotas = app(QuotaBook::class);
        $quotas->saveDrafts($this->day, array_map(fn (Region $region) => [
            'region_id' => $region->id, 'product_id' => $this->product->id, 'quantity' => 500,
        ], $regions), $this->adv);
        $quotas->publish($this->day, array_map(fn (Region $region) => $region->id, $regions), $this->adv);
    };
    // Le planificateur passe à l'heure donnée du 08/10, fuseau métier.
    $this->at = function (string $time): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 '.$time, 'Africa/Algiers'));
        $this->artisan('ramymaak:send-reminders')->assertSuccessful();
    };
    $this->received = fn (User $user, string $type) => DatabaseNotification::query()
        ->whereMorphedTo('notifiable', $user)
        ->where('type', $type)
        ->orderBy('created_at')
        ->get();
    // Données enregistrées, clés triées : MySQL réordonne les clés JSON.
    $this->data = fn (DatabaseNotification $notification) => collect($notification->data)->sortKeys()->all();
    $this->expected = fn (array $data) => collect($data)->sortKeys()->all();
});

describe('N-10 (DISTRIBUTION_REMINDER)', function () {
    it('reminds the regionals of the region in the 30 minutes before the distribution cutoff, once', function () {
        ($this->publishQuotas)($this->centre);

        ($this->at)('09:29');
        expect(($this->received)($this->titular, 'DISTRIBUTION_REMINDER'))->toBeEmpty();

        ($this->at)('09:30');
        ($this->at)('09:45');
        ($this->at)('09:59');

        foreach ([$this->titular, $this->deputy] as $regional) {
            $notifications = ($this->received)($regional, 'DISTRIBUTION_REMINDER');
            expect($notifications)->toHaveCount(1)
                ->and(($this->data)($notifications[0]))->toBe(($this->expected)([
                    'type' => 'DISTRIBUTION_REMINDER',
                    'code' => 'N-10',
                    'title' => 'Rappel : répartition du 08/10 — Centre',
                    'body' => 'Envoyez la répartition avant 10:00.',
                    'url' => '/regional/repartition?day=2026-10-08&region='.$this->centre->id,
                ]))
                ->and($notifications[0]->created_at->toIso8601String())->toBe('2026-10-08T08:30:00+00:00');
        }
        // Ni l'ADV ni l'Admin : N-10 est un rappel de terrain.
        expect(($this->received)($this->adv, 'DISTRIBUTION_REMINDER'))->toBeEmpty()
            ->and(($this->received)($this->admin, 'DISTRIBUTION_REMINDER'))->toBeEmpty();
    });

    it('sends nothing at or after the cutoff: no late reminder after a scheduler restart', function () {
        ($this->publishQuotas)($this->centre);

        ($this->at)('10:00');
        ($this->at)('10:30');

        expect(($this->received)($this->titular, 'DISTRIBUTION_REMINDER'))->toBeEmpty()
            ->and(($this->received)($this->deputy, 'DISTRIBUTION_REMINDER'))->toBeEmpty();
    });

    it('needs published quotas and a distribution not sent yet', function () {
        // Quotas en brouillon : rien.
        ($this->at)('09:30');
        expect(($this->received)($this->titular, 'DISTRIBUTION_REMINDER'))->toBeEmpty();

        // Répartition déjà envoyée : rien.
        ($this->publishQuotas)($this->centre);
        app(LimitBook::class)->publish(($this->regionDay)($this->centre), $this->titular);
        ($this->at)('09:40');
        expect(($this->received)($this->titular, 'DISTRIBUTION_REMINDER'))->toBeEmpty();
    });

    it('only reaches active regionals of the region that day who kept their reminders (EF-NOT-04)', function () {
        $ended = userWithRole(Role::Regional);
        RegionAssignment::factory()->deputy('2026-10-01', '2026-10-07')->create(['region_id' => $this->centre->id, 'user_id' => $ended->id]);
        $upcoming = userWithRole(Role::Regional);
        RegionAssignment::factory()->deputy('2026-10-09', '2026-10-15')->create(['region_id' => $this->centre->id, 'user_id' => $upcoming->id]);
        $revoked = userWithRole(Role::Regional);
        RegionAssignment::factory()->revoked()->create(['region_id' => $this->centre->id, 'user_id' => $revoked->id]);
        $inactive = userWithRole(Role::Regional, ['is_active' => false]);
        RegionAssignment::factory()->create(['region_id' => $this->centre->id, 'user_id' => $inactive->id]);
        $muted = userWithRole(Role::Regional);
        $muted->forceFill(['reminders_enabled' => false])->save();
        RegionAssignment::factory()->create(['region_id' => $this->centre->id, 'user_id' => $muted->id]);
        $elsewhere = userWithRole(Role::Regional);
        RegionAssignment::factory()->create(['region_id' => $this->est->id, 'user_id' => $elsewhere->id]);
        ($this->publishQuotas)($this->centre);

        ($this->at)('09:30');

        expect(($this->received)($this->titular, 'DISTRIBUTION_REMINDER'))->toHaveCount(1)
            ->and(($this->received)($this->deputy, 'DISTRIBUTION_REMINDER'))->toHaveCount(1);
        foreach ([$ended, $upcoming, $revoked, $inactive, $muted, $elsewhere] as $nobody) {
            expect(($this->received)($nobody, 'DISTRIBUTION_REMINDER'))->toBeEmpty();
        }
    });

    it('reminds again before the new cutoff after an extension, and not before its window (I-107)', function () {
        ($this->publishQuotas)($this->centre);
        ($this->at)('09:30');

        $this->travelTo(CarbonImmutable::parse('2026-10-08 09:40', 'Africa/Algiers'));
        $this->calendar->extendCutoff(($this->regionDay)($this->centre), CutoffKind::Distribution, '11:00', $this->adv);
        ($this->at)('09:45');
        ($this->at)('10:00');
        ($this->at)('10:29');
        expect(($this->received)($this->titular, 'DISTRIBUTION_REMINDER'))->toHaveCount(1)
            // Plus de retard à 10:00 : l'heure limite de la région est 11:00.
            ->and(($this->received)($this->adv, 'DISTRIBUTION_LATE'))->toBeEmpty();

        ($this->at)('10:30');
        ($this->at)('10:59');

        $notifications = ($this->received)($this->titular, 'DISTRIBUTION_REMINDER');
        expect($notifications)->toHaveCount(2)
            ->and($notifications->pluck('data.body')->all())->toBe(['Envoyez la répartition avant 10:00.', 'Envoyez la répartition avant 11:00.']);
    });

    it('follows the configured delay (I-108)', function () {
        config(['ramymaak.reminders.distribution' => 15]);
        ($this->publishQuotas)($this->centre);

        ($this->at)('09:44');
        expect(($this->received)($this->titular, 'DISTRIBUTION_REMINDER'))->toBeEmpty();

        ($this->at)('09:45');
        expect(($this->received)($this->titular, 'DISTRIBUTION_REMINDER'))->toHaveCount(1);
    });
});

describe('N-13 (DISTRIBUTION_LATE)', function () {
    it('alerts the ADV of the region and the Admins from the distribution cutoff to the closing, once', function () {
        ($this->publishQuotas)($this->centre);

        ($this->at)('09:59');
        expect(($this->received)($this->adv, 'DISTRIBUTION_LATE'))->toBeEmpty();

        ($this->at)('10:00');
        ($this->at)('15:00');
        ($this->at)('22:59');

        $content = fn (string $space) => ($this->expected)([
            'type' => 'DISTRIBUTION_LATE',
            'code' => 'N-13',
            'title' => 'Répartition en retard — Centre',
            'body' => 'La répartition du 08/10 n\'est pas envoyée (limite 10:00).',
            'url' => $space.'/journees/2026-10-08/suivi',
        ]);
        $adv = ($this->received)($this->adv, 'DISTRIBUTION_LATE');
        $admin = ($this->received)($this->admin, 'DISTRIBUTION_LATE');
        expect($adv)->toHaveCount(1)
            ->and(($this->data)($adv[0]))->toBe($content('/adv'))
            ->and($adv[0]->created_at->toIso8601String())->toBe('2026-10-08T09:00:00+00:00')
            ->and($admin)->toHaveCount(1)
            // L'Admin ouvre le suivi de son propre espace.
            ->and(($this->data)($admin[0]))->toBe($content('/admin'))
            // Les régionaux ont eu N-10 : l'alerte est pour le back-office.
            ->and(($this->received)($this->titular, 'DISTRIBUTION_LATE'))->toBeEmpty();
    });

    it('sends nothing from the closing time on', function () {
        ($this->publishQuotas)($this->centre);

        ($this->at)('23:00');

        expect(($this->received)($this->adv, 'DISTRIBUTION_LATE'))->toBeEmpty()
            ->and(($this->received)($this->admin, 'DISTRIBUTION_LATE'))->toBeEmpty();
    });

    it('needs published quotas and a distribution not sent', function () {
        // Est : quotas en brouillon ; Centre : répartition envoyée.
        ($this->publishQuotas)($this->centre);
        app(LimitBook::class)->publish(($this->regionDay)($this->centre), $this->titular);

        ($this->at)('10:00');

        expect(($this->received)($this->adv, 'DISTRIBUTION_LATE'))->toBeEmpty();
    });

    it('reaches the ADV whose perimeter includes the region that day (RG-S05) and active Admins, reminders off or not', function () {
        $centreAdv = userWithRole(Role::Adv);
        RegionAssignment::factory()->adv()->create(['region_id' => $this->centre->id, 'user_id' => $centreAdv->id]);
        $estAdv = userWithRole(Role::Adv);
        RegionAssignment::factory()->adv()->create(['region_id' => $this->est->id, 'user_id' => $estAdv->id]);
        // Affectation au Centre terminée la veille : plus de périmètre, jamais toutes les régions.
        $formerAdv = userWithRole(Role::Adv);
        RegionAssignment::factory()->adv()->create([
            'region_id' => $this->centre->id, 'user_id' => $formerAdv->id, 'starts_on' => '2026-09-01', 'ends_on' => '2026-10-07',
        ]);
        $inactiveAdv = userWithRole(Role::Adv, ['is_active' => false]);
        $inactiveAdmin = admin(['is_active' => false]);
        // N-13 n'est pas un rappel : il n'est pas coupable (EF-NOT-04).
        $this->adv->forceFill(['reminders_enabled' => false])->save();
        // Est : quotas en brouillon, pas de retard.
        ($this->publishQuotas)($this->centre);

        ($this->at)('10:00');

        foreach ([$this->adv, $centreAdv, $this->admin] as $recipient) {
            expect(($this->received)($recipient, 'DISTRIBUTION_LATE'))->toHaveCount(1);
        }
        foreach ([$estAdv, $formerAdv, $inactiveAdv, $inactiveAdmin] as $nobody) {
            expect(($this->received)($nobody, 'DISTRIBUTION_LATE'))->toBeEmpty();
        }
    });

    it('alerts again after an extension of the distribution cutoff, from the new cutoff (I-107)', function () {
        ($this->publishQuotas)($this->centre);
        ($this->at)('10:00');

        $this->travelTo(CarbonImmutable::parse('2026-10-08 10:05', 'Africa/Algiers'));
        $this->calendar->extendCutoff(($this->regionDay)($this->centre), CutoffKind::Distribution, '11:00', $this->adv);
        ($this->at)('10:30');
        expect(($this->received)($this->adv, 'DISTRIBUTION_LATE'))->toHaveCount(1);

        ($this->at)('11:00');

        expect(($this->received)($this->adv, 'DISTRIBUTION_LATE')->pluck('data.body')->all())->toBe([
            'La répartition du 08/10 n\'est pas envoyée (limite 10:00).',
            'La répartition du 08/10 n\'est pas envoyée (limite 11:00).',
        ])
            // Le rappel N-10 de la nouvelle heure est parti entre-temps.
            ->and(($this->received)($this->titular, 'DISTRIBUTION_REMINDER')->pluck('data.body')->all())->toBe(['Envoyez la répartition avant 11:00.']);
    });
});
