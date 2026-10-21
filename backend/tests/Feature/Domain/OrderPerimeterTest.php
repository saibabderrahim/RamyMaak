<?php

use App\Domain\Access\Perimeter;
use App\Domain\Access\Role;
use App\Domain\Referentials\AssignmentKind;
use App\Models\BusinessDay;
use App\Models\Client;
use App\Models\Order;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;

// Périmètre des commandes jugé à la journée de chaque commande (RG-S05,
// I-12, RG-S04 ; contrat du lot 6, §2) : file ADV multi-journées.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00', 'Africa/Algiers'));
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->est = Region::factory()->create(['code' => 'EST']);
    $this->days = [
        '07' => BusinessDay::factory()->on('2026-10-07')->create(),
        '08' => BusinessDay::factory()->on('2026-10-08')->create(),
        '09' => BusinessDay::factory()->on('2026-10-09')->create(),
    ];
    $this->client = Client::factory()->create(['region_id' => $this->centre->id]);
    // Une commande par journée et par région (région figée à l'envoi).
    $this->orders = [];
    foreach ($this->days as $key => $day) {
        foreach (['CENTRE' => $this->centre, 'EST' => $this->est] as $code => $region) {
            $this->orders["{$code}-{$key}"] = Order::factory()->validated()->create([
                'client_id' => $this->client->id, 'region_id' => $region->id, 'business_day_id' => $day->id,
            ])->id;
        }
    }
    $this->visible = fn (User $user): array => app(Perimeter::class)->constrainOrders(Order::query(), $user)
        ->orderBy('id')
        ->pluck('id')
        ->map(fn (int $id) => array_search($id, $this->orders, true))
        ->all();
    $this->assign = fn (User $user, Region $region, AssignmentKind $kind, string $from, ?string $to = null, bool $revoked = false) => RegionAssignment::factory()->create([
        'user_id' => $user->id, 'region_id' => $region->id, 'kind' => $kind, 'starts_on' => $from, 'ends_on' => $to, 'revoked_at' => $revoked ? now() : null,
    ]);
});

it('leaves every order to the Admin and to an ADV never assigned to a region', function () {
    $all = ['CENTRE-07', 'EST-07', 'CENTRE-08', 'EST-08', 'CENTRE-09', 'EST-09'];

    expect(($this->visible)(admin()))->toBe($all)
        ->and(($this->visible)(userWithRole(Role::Adv)))->toBe($all);
});

it('limits a restricted ADV to the regions of its ADV assignments active at the day of each order (RG-S05)', function () {
    $adv = userWithRole(Role::Adv);
    // Centre du 08 au 09 ; Est seulement le 07, puis révoquée une affectation au 09.
    ($this->assign)($adv, $this->centre, AssignmentKind::Adv, '2026-10-08', '2026-10-09');
    ($this->assign)($adv, $this->est, AssignmentKind::Adv, '2026-10-07', '2026-10-07');
    ($this->assign)($adv, $this->est, AssignmentKind::Adv, '2026-10-09', null, revoked: true);
    // Une affectation de régional ne compte pas pour l'ADV.
    ($this->assign)($adv, $this->est, AssignmentKind::Titular, '2026-10-01');

    expect(($this->visible)($adv))->toBe(['EST-07', 'CENTRE-08', 'CENTRE-09']);

    // Toutes ses affectations terminées : plus rien, jamais toutes les régions.
    $former = userWithRole(Role::Adv);
    ($this->assign)($former, $this->centre, AssignmentKind::Adv, '2026-09-01', '2026-09-30');
    expect(($this->visible)($former))->toBe([]);
});

it('limits a regional to the titular or deputy assignments at the day of each order, and a client to its own orders', function () {
    $regional = userWithRole(Role::Regional);
    ($this->assign)($regional, $this->centre, AssignmentKind::Titular, '2026-10-01');
    ($this->assign)($regional, $this->est, AssignmentKind::Deputy, '2026-10-09', '2026-10-09');
    expect(($this->visible)($regional))->toBe(['CENTRE-07', 'CENTRE-08', 'CENTRE-09', 'EST-09']);

    $account = userWithRole(Role::Client, ['client_id' => $this->client->id]);
    $other = userWithRole(Role::Client, ['client_id' => Client::factory()->create()->id]);
    expect(($this->visible)($account))->toHaveCount(6)
        ->and(($this->visible)($other))->toBe([])
        ->and(($this->visible)(User::factory()->create()))->toBe([]);
});
