<?php

use App\Domain\Access\Perimeter;
use App\Domain\Access\Role;
use App\Domain\Referentials\ClientRegistry;
use App\Models\Client;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\User;
use App\Support\BusinessClock;

function perimeter(): Perimeter
{
    return app(Perimeter::class);
}

/** @return list<string> */
function visibleCodes(User $user, $day = null): array
{
    return perimeter()->clients($user, $day)->orderBy('code')->pluck('code')->all();
}

beforeEach(function () {
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->est = Region::factory()->create(['code' => 'EST']);
    Client::factory()->create(['code' => 'C1', 'region_id' => $this->centre->id]);
    Client::factory()->blocked()->create(['code' => 'C2', 'region_id' => $this->centre->id]);
    Client::factory()->inactive()->create(['code' => 'C3', 'region_id' => $this->centre->id]);
    Client::factory()->create(['code' => 'E1', 'region_id' => $this->est->id]);
});

it('gives the Admin every region and every client', function () {
    $admin = userWithRole(Role::Admin);

    expect(perimeter()->regionIds($admin))->toBeNull()
        ->and(visibleCodes($admin))->toBe(['C1', 'C2', 'C3', 'E1']);
});

it('gives the ADV every region unless ADV assignments restrict it (RG-S05)', function () {
    $adv = userWithRole(Role::Adv);
    expect(perimeter()->regionIds($adv))->toBeNull();

    RegionAssignment::factory()->adv()->create(['user_id' => $adv->id, 'region_id' => $this->est->id]);

    expect(perimeter()->regionIds($adv))->toBe([$this->est->id])
        ->and(visibleCodes($adv))->toBe(['E1'])
        ->and(perimeter()->canAccessRegion($adv, $this->centre->id))->toBeFalse();
});

it('limits a regional to active and blocked clients of the regions they cover (RG-S01)', function () {
    $regional = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['user_id' => $regional->id, 'region_id' => $this->centre->id]);

    expect(perimeter()->regionIds($regional))->toBe([$this->centre->id])
        ->and(visibleCodes($regional))->toBe(['C1', 'C2'])
        ->and(perimeter()->canAccessClient($regional, Client::where('code', 'C3')->sole()))->toBeFalse()
        ->and(perimeter()->canAccessClient($regional, Client::where('code', 'E1')->sole()))->toBeFalse()
        ->and(perimeter()->canAccessClient($regional, Client::where('code', 'C2')->sole()))->toBeTrue();
});

it('gives a regional without assignment no client at all', function () {
    $regional = userWithRole(Role::Regional);

    expect(perimeter()->regionIds($regional))->toBe([])
        ->and(visibleCodes($regional))->toBe([]);
});

it('gives a deputy the region only during the deputy period (§3.4)', function () {
    $deputy = userWithRole(Role::Regional);
    $today = BusinessClock::today();
    RegionAssignment::factory()->deputy($today->addDays(2)->format('Y-m-d'), $today->addDays(5)->format('Y-m-d'))
        ->create(['user_id' => $deputy->id, 'region_id' => $this->est->id]);

    expect(perimeter()->regionIds($deputy, $today))->toBe([])
        ->and(perimeter()->regionIds($deputy, $today->addDays(2)))->toBe([$this->est->id])
        ->and(perimeter()->regionIds($deputy, $today->addDays(5)))->toBe([$this->est->id])
        ->and(perimeter()->regionIds($deputy, $today->addDays(6)))->toBe([]);
});

it('drops a revoked assignment immediately (RG-S03)', function () {
    $regional = userWithRole(Role::Regional);
    RegionAssignment::factory()->revoked()->create(['user_id' => $regional->id, 'region_id' => $this->centre->id]);

    expect(perimeter()->regionIds($regional))->toBe([]);
});

it('does not give a regional the regions of an ADV assignment', function () {
    $regional = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['user_id' => $regional->id, 'region_id' => $this->centre->id, 'kind' => 'ADV']);

    expect(perimeter()->regionIds($regional))->toBe([]);
});

it('follows a client into its new region from the next day only (RG-S04)', function () {
    $centreRegional = userWithRole(Role::Regional);
    $estRegional = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['user_id' => $centreRegional->id, 'region_id' => $this->centre->id]);
    RegionAssignment::factory()->create(['user_id' => $estRegional->id, 'region_id' => $this->est->id]);
    $client = Client::where('code', 'C1')->sole();

    app(ClientRegistry::class)->update($client, ['region_id' => $this->est->id], null);
    $today = BusinessClock::today();
    $tomorrow = $today->addDay();

    expect(visibleCodes($centreRegional, $today))->toContain('C1')
        ->and(visibleCodes($estRegional, $today))->not->toContain('C1')
        ->and(visibleCodes($centreRegional, $tomorrow))->not->toContain('C1')
        ->and(visibleCodes($estRegional, $tomorrow))->toContain('C1')
        ->and(perimeter()->canAccessClient($estRegional, $client->refresh(), $tomorrow))->toBeTrue()
        ->and(perimeter()->canAccessClient($estRegional, $client, $today))->toBeFalse();
});

it('limits a client account to its own client', function () {
    $client = Client::where('code', 'E1')->sole();
    $account = userWithRole(Role::Client, ['client_id' => $client->id]);

    expect(visibleCodes($account))->toBe(['E1'])
        ->and(perimeter()->regionIds($account))->toBe([$this->est->id])
        ->and(perimeter()->canAccessRegion($account, $this->est->id))->toBeFalse()
        ->and(perimeter()->canAccessClient($account, $client))->toBeTrue()
        ->and(perimeter()->canAccessClient($account, Client::where('code', 'C1')->sole()))->toBeFalse();
});

it('gives nothing to an account without role or without client', function () {
    expect(visibleCodes(User::factory()->create()))->toBe([])
        ->and(visibleCodes(userWithRole(Role::Client)))->toBe([]);
});

it('agrees between the SQL filter and the unit check for every user and client', function () {
    $regional = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['user_id' => $regional->id, 'region_id' => $this->centre->id]);
    $adv = userWithRole(Role::Adv);
    RegionAssignment::factory()->adv()->create(['user_id' => $adv->id, 'region_id' => $this->est->id]);
    $users = [userWithRole(Role::Admin), $adv, $regional, userWithRole(Role::Client, ['client_id' => Client::where('code', 'C2')->sole()->id])];

    foreach ($users as $user) {
        $visible = visibleCodes($user);
        foreach (Client::all() as $client) {
            expect(perimeter()->canAccessClient($user, $client))->toBe(in_array($client->code, $visible, true), "{$user->appRole()?->value} / {$client->code}");
        }
    }
});
