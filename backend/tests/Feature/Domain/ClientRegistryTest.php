<?php

use App\Domain\Access\Role;
use App\Domain\Referentials\ClientRegistry;
use App\Domain\Referentials\ClientStatus;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Region;
use App\Support\BusinessClock;
use Illuminate\Support\Facades\DB;

function registry(): ClientRegistry
{
    return app(ClientRegistry::class);
}

beforeEach(function () {
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->est = Region::factory()->create(['code' => 'EST']);
    $this->ouest = Region::factory()->create(['code' => 'OUEST']);
});

it('creates a client in its region immediately, active by default', function () {
    $client = registry()->create(['code' => 'C0012', 'name' => 'Client A', 'region_id' => $this->centre->id], null);

    expect($client->region_id)->toBe($this->centre->id)
        ->and($client->status)->toBe(ClientStatus::Active)
        ->and($client->next_region_id)->toBeNull();
});

it('moves a client to another region from the next day (RG-S04)', function () {
    $client = Client::factory()->create(['region_id' => $this->centre->id]);
    $today = BusinessClock::today();

    registry()->update($client, ['region_id' => $this->est->id], null);

    expect($client->region_id)->toBe($this->centre->id)
        ->and($client->next_region_id)->toBe($this->est->id)
        ->and($client->region_change_on->format('Y-m-d'))->toBe($today->addDay()->format('Y-m-d'))
        ->and($client->effectiveRegionId($today))->toBe($this->centre->id)
        ->and($client->effectiveRegionId($today->addDay()))->toBe($this->est->id);
});

it('cancels the pending change when the client goes back to its current region', function () {
    $client = Client::factory()->create(['region_id' => $this->centre->id]);
    registry()->update($client, ['region_id' => $this->est->id], null);

    registry()->update($client, ['region_id' => $this->centre->id], null);

    expect($client->next_region_id)->toBeNull()->and($client->region_change_on)->toBeNull();
});

it('replaces a pending change by a newer one', function () {
    $client = Client::factory()->create(['region_id' => $this->centre->id]);
    registry()->update($client, ['region_id' => $this->est->id], null);

    registry()->update($client, ['region_id' => $this->ouest->id], null);

    expect($client->next_region_id)->toBe($this->ouest->id);
});

it('settles a change already due before scheduling a new one', function () {
    $client = Client::factory()->create(['region_id' => $this->centre->id]);
    registry()->update($client, ['region_id' => $this->est->id], null);

    $this->travel(2)->days();
    registry()->update($client, ['region_id' => $this->ouest->id], null);

    expect($client->region_id)->toBe($this->est->id)
        ->and($client->next_region_id)->toBe($this->ouest->id);
});

it('applies due region changes once, idempotently', function () {
    $due = Client::factory()->create(['region_id' => $this->centre->id]);
    $later = Client::factory()->create(['region_id' => $this->centre->id]);
    registry()->update($due, ['region_id' => $this->est->id], null);
    $this->travel(1)->days();
    registry()->update($later, ['region_id' => $this->ouest->id], null);

    expect(registry()->applyDueRegionChanges())->toBe(1)
        ->and(registry()->applyDueRegionChanges())->toBe(0);

    expect($due->refresh())->region_id->toBe($this->est->id)->next_region_id->toBeNull()
        ->and($later->refresh())->region_id->toBe($this->centre->id)->next_region_id->toBe($this->ouest->id);

    $this->artisan('ramymaak:apply-region-changes')->assertSuccessful();
});

it('never changes the client code (ERP key)', function () {
    $client = Client::factory()->create(['code' => 'C0012']);

    registry()->update($client, ['code' => 'C9999', 'name' => 'Nouveau nom'], null);

    expect($client->code)->toBe('C0012')->and($client->name)->toBe('Nouveau nom');
});

it('requires a reason to block a client (EF-CLI-04)', function () {
    $client = Client::factory()->create();

    expect(fn () => registry()->changeStatus($client, ClientStatus::Blocked, '  ', null))->toThrow(InvalidArgumentException::class);

    $admin = admin();
    registry()->changeStatus($client, ClientStatus::Blocked, 'Impayés', $admin);

    expect($client->refresh())
        ->status->toBe(ClientStatus::Blocked)
        ->status_reason->toBe('Impayés')
        ->status_changed_by->toBe($admin->id)
        ->status_changed_at->not->toBeNull();
});

it('deactivates the accounts of an inactive client and revokes their sessions (§3.5)', function () {
    $client = Client::factory()->create();
    $other = Client::factory()->create();
    $account = userWithRole(Role::Client, ['client_id' => $client->id]);
    $otherAccount = userWithRole(Role::Client, ['client_id' => $other->id]);
    DB::table('sessions')->insert(['id' => 'phone', 'user_id' => $account->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);

    registry()->changeStatus($client, ClientStatus::Inactive, 'Cessation d\'activité', admin());

    expect($account->fresh()->is_active)->toBeFalse()
        ->and($otherAccount->fresh()->is_active)->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $account->id)->exists())->toBeFalse();
});

it('journals region and status changes with old and new values', function () {
    $client = Client::factory()->create(['region_id' => $this->centre->id]);
    $admin = admin();
    $this->actingAs($admin);

    registry()->update($client, ['region_id' => $this->est->id, 'status' => 'BLOCKED', 'status_reason' => 'Impayés'], $admin);

    $entries = AuditLog::query()->where('subject_type', 'client')->where('subject_id', $client->id)->where('event', 'updated')->get();
    $changes = $entries->flatMap(fn ($e) => array_keys($e->properties['attributes'] ?? []))->unique()->values()->all();
    expect($changes)->toContain('next_region_id', 'region_change_on', 'status', 'status_reason')
        ->and($entries->first()->description)->toBe('Client modifié')
        ->and($entries->first()->causer_id)->toBe($admin->id);
});
