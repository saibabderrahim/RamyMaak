<?php

use App\Domain\Access\Role;
use App\Models\Client;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;

// Canal client.{clientId} (§9.6, RG-S02 ; contrat du lot 4, §4) : bornes
// envoyées au client (LimitsUpdated), réservé aux comptes de ce client.
// Autorisation par POST /broadcasting/auth (session Sanctum, Reverb).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 08:30', 'Africa/Algiers'));
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'ramymaak-test-key',
        'broadcasting.connections.reverb.secret' => 'ramymaak-test-secret',
        'broadcasting.connections.reverb.app_id' => '100001',
        'broadcasting.connections.reverb.options.host' => 'localhost',
    ]);
    // Les canaux se déclarent sur le diffuseur par défaut au démarrage (pilote
    // « null » en test) : on les déclare aussi sur Reverb.
    require base_path('routes/channels.php');

    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->a = Client::factory()->create(['code' => 'C0012', 'region_id' => $this->centre->id]);
    $this->b = Client::factory()->create(['code' => 'C0047', 'region_id' => $this->centre->id]);
    $this->accountA = userWithRole(Role::Client, ['client_id' => $this->a->id]);
    $this->secondA = userWithRole(Role::Client, ['client_id' => $this->a->id]);
    $this->accountB = userWithRole(Role::Client, ['client_id' => $this->b->id]);
    $this->regional = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['region_id' => $this->centre->id, 'user_id' => $this->regional->id]);

    $this->auth = function (?User $user, string $channel) {
        forgetGuards();
        if ($user !== null) {
            $this->actingAs($user);
        }

        return $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-'.$channel]);
    };
    $this->allowed = fn (User $user, string $channel) => ($this->auth)($user, $channel)
        ->assertOk()
        ->assertJson(fn ($json) => $json->where('auth', fn (string $auth) => str_starts_with($auth, 'ramymaak-test-key:')));
    $this->denied = fn (User $user, string $channel) => expectProblem(($this->auth)($user, $channel), 403, 'FORBIDDEN');
});

it('opens client.{id} to the accounts of that client only', function () {
    ($this->allowed)($this->accountA, "client.{$this->a->id}");
    ($this->allowed)($this->secondA, "client.{$this->a->id}");
    ($this->allowed)($this->accountB, "client.{$this->b->id}");

    ($this->denied)($this->accountA, "client.{$this->b->id}");
    ($this->denied)($this->accountB, "client.{$this->a->id}");
});

it('refuses client.{id} to the staff, even with the region of the client in its perimeter', function () {
    ($this->denied)($this->regional, "client.{$this->a->id}");
    ($this->denied)(userWithRole(Role::Adv), "client.{$this->a->id}");
    ($this->denied)(admin(), "client.{$this->a->id}");
    // Compte encore rattaché au client, passé à un autre rôle.
    ($this->denied)(userWithRole(Role::Regional, ['client_id' => $this->a->id]), "client.{$this->a->id}");
});

it('refuses non-numeric client identifiers with a 403, not a server error', function () {
    ($this->denied)($this->accountA, 'client.abc');
    ($this->denied)($this->accountA, "client.{$this->a->id}x");
    ($this->denied)($this->accountA, 'client.');
});

it('lets a client account receive its own notifications (N-03, N-04) on user.{id}, never the region channels', function () {
    ($this->allowed)($this->accountA, "user.{$this->accountA->id}");
    ($this->denied)($this->accountA, "user.{$this->secondA->id}");
    ($this->denied)($this->accountA, "region-day.{$this->centre->id}.2026-10-08");
    ($this->denied)($this->accountA, 'adv.2026-10-08');
});

it('refuses guests', function () {
    expectProblem(($this->auth)(null, "client.{$this->a->id}"), 401, 'UNAUTHENTICATED');
});
