<?php

use App\Domain\Access\Role;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;

// Autorisation des canaux privés par POST /broadcasting/auth (§9.6, RG-S02 ;
// contrat du lot 3, §6) : session Sanctum, Reverb (protocole Pusher).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 07:30', 'Africa/Algiers'));
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
    $this->est = Region::factory()->create(['code' => 'EST']);
    $this->titular = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['region_id' => $this->centre->id, 'user_id' => $this->titular->id]);
    $this->deputy = userWithRole(Role::Regional);
    RegionAssignment::factory()->deputy('2026-10-08', '2026-10-08')->create(['region_id' => $this->centre->id, 'user_id' => $this->deputy->id]);
    $this->otherRegional = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['region_id' => $this->est->id, 'user_id' => $this->otherRegional->id]);

    $this->auth = function (?User $user, string $channel) {
        forgetGuards();
        if ($user !== null) {
            $this->actingAs($user);
        }

        return $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-'.$channel]);
    };
    $this->allowed = fn (?User $user, string $channel) => ($this->auth)($user, $channel)
        ->assertOk()
        ->assertJson(fn ($json) => $json->where('auth', fn (string $auth) => str_starts_with($auth, 'ramymaak-test-key:')));
    $this->denied = fn (User $user, string $channel) => expectProblem(($this->auth)($user, $channel), 403, 'FORBIDDEN');
});

it('opens user.{id} to that user only', function () {
    ($this->allowed)($this->titular, "user.{$this->titular->id}");
    ($this->denied)($this->titular, "user.{$this->deputy->id}");
    ($this->denied)(admin(), "user.{$this->titular->id}");
});

it('opens region-day.{region}.{day} to the regionals of the region that day, ADV and Admin', function () {
    $channel = "region-day.{$this->centre->id}.2026-10-08";

    ($this->allowed)($this->titular, $channel);
    ($this->allowed)($this->deputy, $channel);
    ($this->allowed)(userWithRole(Role::Adv), $channel);
    ($this->allowed)(admin(), $channel);

    ($this->denied)($this->otherRegional, $channel);
    ($this->denied)(userWithRole(Role::Client), $channel);
    // Le suppléant ne l'est que le 08/10 ; le titulaire reste titulaire le 09/10.
    ($this->denied)($this->deputy, "region-day.{$this->centre->id}.2026-10-09");
    ($this->allowed)($this->titular, "region-day.{$this->centre->id}.2026-10-09");
    // Date invalide.
    ($this->denied)($this->titular, "region-day.{$this->centre->id}.2026-13-45");
});

it('limits region-day channels to the regions of a restricted ADV (RG-S05)', function () {
    $restricted = userWithRole(Role::Adv);
    RegionAssignment::factory()->adv()->create(['region_id' => $this->est->id, 'user_id' => $restricted->id]);

    ($this->allowed)($restricted, "region-day.{$this->est->id}.2026-10-08");
    ($this->denied)($restricted, "region-day.{$this->centre->id}.2026-10-08");
});

it('opens adv.{day} to ADV and Admin only', function () {
    ($this->allowed)(userWithRole(Role::Adv), 'adv.2026-10-08');
    ($this->allowed)(admin(), 'adv.2026-10-08');

    ($this->denied)($this->titular, 'adv.2026-10-08');
    ($this->denied)(userWithRole(Role::Client), 'adv.2026-10-08');
    ($this->denied)(userWithRole(Role::Adv), 'adv.08-10-2026');
});

it('refuses unknown channels and guests', function () {
    ($this->denied)(admin(), 'client.1');
    expectProblem(($this->auth)(null, "user.{$this->titular->id}"), 401, 'UNAUTHENTICATED');
});
