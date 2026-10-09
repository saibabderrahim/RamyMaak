<?php

use App\Domain\Access\Role;
use App\Domain\Referentials\ClientRegistry;
use App\Domain\Referentials\ClientStatus;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\User;
use App\Support\BusinessClock;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as SpatieRole;

beforeEach(function () {
    $this->admin = admin();
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    $this->category = ClientCategory::factory()->create(['code' => 'DETAILLANT', 'name' => 'Détaillant']);

    // Régional titulaire d'une région (RG-S01).
    $this->regionalOf = function (Region $region): User {
        $regional = userWithRole(Role::Regional);
        RegionAssignment::factory()->create(['user_id' => $regional->id, 'region_id' => $region->id]);

        return $regional;
    };

    $this->validPayload = fn (array $overrides = []) => [
        'code' => 'c0012',
        'name' => 'Superette El Baraka',
        'trade_name' => 'El Baraka',
        'category_id' => $this->category->id,
        'region_id' => $this->centre->id,
        'address' => '12 rue des Martyrs',
        'wilaya' => 'Alger',
        'commune' => 'Bab Ezzouar',
        'lat' => 36.7212345,
        'lng' => 3.1812345,
        'phone' => '0550123456',
        'email' => 'contact@elbaraka.dz',
        ...$overrides,
    ];
});

describe('rights (§3.2)', function () {
    it('lets staff with client.view list clients, but not client accounts', function () {
        Client::factory()->create(['region_id' => $this->centre->id]);

        foreach ([$this->admin, userWithRole(Role::Adv), ($this->regionalOf)($this->centre)] as $user) {
            $this->actingAs($user)->getJson('/api/v1/clients')->assertOk()->assertJsonPath('meta.total', 1);
        }

        $account = userWithRole(Role::Client, ['client_id' => Client::factory()->create()->id]);
        expectProblem($this->actingAs($account)->getJson('/api/v1/clients'), 403, 'FORBIDDEN');
    });

    it('reserves creation and modification to client.manage, before any validation', function (Role $role) {
        $client = Client::factory()->create(['region_id' => $this->centre->id]);
        $user = $role === Role::Regional ? ($this->regionalOf)($this->centre) : userWithRole($role, $role === Role::Client ? ['client_id' => $client->id] : []);
        $this->actingAs($user);

        expectProblem($this->postJson('/api/v1/clients', []), 403, 'FORBIDDEN');
        expectProblem($this->patchJson("/api/v1/clients/{$client->id}", ['name' => '']), 403, 'FORBIDDEN');
        expectProblem($this->patchJson("/api/v1/clients/{$client->id}", ['status' => 'BLOCKED']), 403, 'FORBIDDEN');
        expect($client->fresh()->status)->toBe(ClientStatus::Active);
    })->with([Role::Adv, Role::Regional, Role::Client]);

    it('requires an authenticated session', function () {
        expectProblem($this->getJson('/api/v1/clients'), 401, 'UNAUTHENTICATED');
    });
});

describe('perimeter (RG-S01, RG-S02)', function () {
    beforeEach(function () {
        Client::factory()->create(['code' => 'C1', 'region_id' => $this->centre->id]);
        Client::factory()->blocked()->create(['code' => 'C2', 'region_id' => $this->centre->id]);
        Client::factory()->inactive()->create(['code' => 'C3', 'region_id' => $this->centre->id]);
        Client::factory()->create(['code' => 'E1', 'region_id' => $this->est->id]);
    });

    it('gives the Admin and an unrestricted ADV every client', function () {
        $this->actingAs($this->admin)->getJson('/api/v1/clients')->assertJsonPath('data.*.code', ['C1', 'C2', 'C3', 'E1']);
        $this->actingAs(userWithRole(Role::Adv))->getJson('/api/v1/clients')->assertJsonPath('data.*.code', ['C1', 'C2', 'C3', 'E1']);
    });

    it('limits a regional to the active and blocked clients of their regions', function () {
        $this->actingAs(($this->regionalOf)($this->centre));

        $this->getJson('/api/v1/clients')->assertJsonPath('data.*.code', ['C1', 'C2']);
        $this->getJson('/api/v1/clients?filter[status]=INACTIVE')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/clients/'.Client::where('code', 'C2')->value('id'))->assertOk()->assertJsonPath('status', 'BLOCKED');
    });

    it('answers 404 outside the perimeter, exactly as for an unknown client', function () {
        $this->actingAs(($this->regionalOf)($this->centre));
        $unknown = $this->getJson('/api/v1/clients/999999');
        expectProblem($unknown, 404, 'NOT_FOUND');

        foreach (['E1', 'C3'] as $code) {
            $response = $this->getJson('/api/v1/clients/'.Client::where('code', $code)->value('id'));
            expectProblem($response, 404, 'NOT_FOUND');
            expect($response->json())->toBe($unknown->json());
        }
    });

    it('restricts an ADV with ADV assignments to those regions (RG-S05)', function () {
        $adv = userWithRole(Role::Adv);
        RegionAssignment::factory()->adv()->create(['user_id' => $adv->id, 'region_id' => $this->est->id]);
        $this->actingAs($adv);

        $this->getJson('/api/v1/clients')->assertJsonPath('data.*.code', ['E1']);
        expectProblem($this->getJson('/api/v1/clients/'.Client::where('code', 'C1')->value('id')), 404, 'NOT_FOUND');
    });

    it('gives a regional without assignment no client', function () {
        $this->actingAs(userWithRole(Role::Regional))->getJson('/api/v1/clients')->assertJsonPath('meta.total', 0);
    });

    it('moves a client to its new region from the next day only (RG-S04)', function () {
        $centreRegional = ($this->regionalOf)($this->centre);
        $estRegional = ($this->regionalOf)($this->est);
        $client = Client::where('code', 'C1')->sole();
        $tomorrow = BusinessClock::today()->addDay()->format('Y-m-d');

        $this->actingAs($this->admin)->patchJson("/api/v1/clients/{$client->id}", ['region_id' => $this->est->id])
            ->assertOk()
            ->assertJsonPath('region.code', 'CENTRE')
            ->assertJsonPath('pending_region_change', [
                'region' => ['id' => $this->est->id, 'code' => 'EST', 'name' => 'Est'],
                'effective_on' => $tomorrow,
            ]);

        // Aujourd'hui : toujours chez le régional du Centre.
        $this->actingAs($centreRegional)->getJson('/api/v1/clients')->assertJsonPath('data.*.code', ['C1', 'C2']);
        $this->getJson("/api/v1/clients/{$client->id}")->assertOk()->assertJsonPath('pending_region_change.effective_on', $tomorrow);
        $this->actingAs($estRegional)->getJson('/api/v1/clients')->assertJsonPath('data.*.code', ['E1']);
        expectProblem($this->getJson("/api/v1/clients/{$client->id}"), 404, 'NOT_FOUND');
        $this->actingAs($this->admin)->getJson("/api/v1/clients?filter[region_id]={$this->est->id}")->assertJsonPath('data.*.code', ['E1']);

        // Le lendemain, même avant la tâche nocturne : chez le régional de l'Est.
        $this->travel(1)->days();

        $this->actingAs($estRegional)->getJson('/api/v1/clients')->assertJsonPath('data.*.code', ['C1', 'E1']);
        $this->getJson("/api/v1/clients/{$client->id}")
            ->assertOk()
            ->assertJsonPath('region', ['id' => $this->est->id, 'code' => 'EST', 'name' => 'Est'])
            ->assertJsonPath('pending_region_change', null);
        $this->actingAs($centreRegional)->getJson('/api/v1/clients')->assertJsonPath('data.*.code', ['C2']);
        expectProblem($this->getJson("/api/v1/clients/{$client->id}"), 404, 'NOT_FOUND');
        session()->flush(); // L'Admin se reconnecte le lendemain (session du personnel limitée, EF-AUTH-06).
        $this->actingAs($this->admin)->getJson("/api/v1/clients?filter[region_id]={$this->est->id}")->assertJsonPath('data.*.code', ['C1', 'E1']);
    });
});

describe('client account (§3.2 : sa fiche)', function () {
    it('reads its own fiche only, without the accounts', function () {
        $own = Client::factory()->blocked('Impayés')->create(['code' => 'C0012', 'region_id' => $this->centre->id]);
        $other = Client::factory()->create(['region_id' => $this->centre->id]);
        $this->actingAs(userWithRole(Role::Client, ['client_id' => $own->id]));

        $this->getJson("/api/v1/clients/{$own->id}")
            ->assertOk()
            ->assertJsonPath('code', 'C0012')
            ->assertJsonPath('status_reason', 'Impayés')
            ->assertJsonMissingPath('accounts');
        expectProblem($this->getJson("/api/v1/clients/{$other->id}"), 404, 'NOT_FOUND');
    });

    it('sees nothing when not attached to a client', function () {
        $client = Client::factory()->create();
        $this->actingAs(userWithRole(Role::Client));

        expectProblem($this->getJson("/api/v1/clients/{$client->id}"), 404, 'NOT_FOUND');
    });
});

describe('resource', function () {
    it('returns the full fiche, with the accounts for account managers only', function () {
        $client = app(ClientRegistry::class)->create(($this->validPayload)(['code' => 'C0012']), $this->admin);
        $account = userWithRole(Role::Client, ['username' => 'c0012', 'client_id' => $client->id]);
        userWithRole(Role::Client, ['username' => 'c0012-b', 'client_id' => $client->id, 'is_active' => false]);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/clients/{$client->id}")->assertOk();

        $response->assertJsonPath('code', 'C0012')
            ->assertJsonPath('category', ['id' => $this->category->id, 'code' => 'DETAILLANT', 'name' => 'Détaillant'])
            ->assertJsonPath('region', ['id' => $this->centre->id, 'code' => 'CENTRE', 'name' => 'Centre'])
            ->assertJsonPath('pending_region_change', null)
            ->assertJsonPath('status', 'ACTIVE')
            ->assertJsonPath('status_label', 'Actif')
            ->assertJsonPath('accounts.0', ['id' => $account->id, 'username' => 'c0012', 'is_active' => true, 'last_login_at' => null])
            ->assertJsonPath('accounts.1.is_active', false);
        expect($response->json('lat'))->toBe(36.7212345)
            ->and($response->json('lng'))->toBe(3.1812345)
            ->and(array_keys($response->json()))->toBe([
                'id', 'code', 'name', 'trade_name', 'category', 'region', 'pending_region_change',
                'address', 'wilaya', 'commune', 'lat', 'lng', 'phone', 'phone2', 'email',
                'nif', 'nis', 'rc', 'ai', 'status', 'status_label', 'status_reason', 'status_changed_at',
                'accounts', 'created_at', 'updated_at',
            ]);

        $this->actingAs(userWithRole(Role::Adv))->getJson("/api/v1/clients/{$client->id}")->assertOk()->assertJsonMissingPath('accounts');
        $this->actingAs($this->admin)->getJson('/api/v1/clients')->assertJsonCount(2, 'data.0.accounts');
    });

    it('loads a page of clients with a constant number of queries', function () {
        $this->actingAs($this->admin);
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/clients')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $make = fn () => userWithRole(Role::Client, ['client_id' => Client::factory()->create([
            'region_id' => $this->centre->id,
            'category_id' => $this->category->id,
        ])->id]);

        $make();
        app(ClientRegistry::class)->update(Client::query()->first(), ['region_id' => $this->est->id], null);
        $queries(); // Réchauffe les caches (permissions, paramètres).
        $few = $queries();

        foreach (range(1, 5) as $ignored) {
            app(ClientRegistry::class)->update($make()->client, ['region_id' => $this->est->id], null);
        }

        expect($queries())->toBe($few);
    });
});

describe('list', function () {
    beforeEach(function () {
        $other = ClientCategory::factory()->create();
        Client::factory()->create(['code' => 'C0012', 'name' => 'Superette El Baraka', 'trade_name' => 'Baraka', 'phone' => '0550 12 34 56', 'region_id' => $this->centre->id, 'category_id' => $this->category->id]);
        Client::factory()->blocked()->create(['code' => 'C0047', 'name' => 'Grossiste Amine', 'phone' => '0661000000', 'region_id' => $this->est->id, 'category_id' => $other->id]);
        Client::factory()->create(['code' => 'D_100', 'name' => 'Alimentation Générale', 'trade_name' => 'Chez Ali', 'phone2' => '0770998877', 'region_id' => $this->centre->id]);
        $this->actingAs($this->admin);
    });

    it('searches by code, company name, trade name and phone', function (string $term, array $codes) {
        $this->getJson('/api/v1/clients?filter[search]='.urlencode($term))->assertOk()->assertJsonPath('data.*.code', $codes);
    })->with([
        'code' => ['c00', ['C0012', 'C0047']],
        'underscore in a code' => ['d_1', ['D_100']],
        'underscore read literally' => ['c0_', []],
        'wildcards read literally' => ['C%1', []],
        'company name' => ['amine', ['C0047']],
        'trade name' => ['chez ali', ['D_100']],
        'phone with spaces' => ['0550123456', ['C0012']],
        'phone typed with spaces' => ['0770 99', ['D_100']],
        'comma kept in the term' => ['Baraka, Alger', []],
    ]);

    it('filters by region, category and status', function () {
        $this->getJson("/api/v1/clients?filter[region_id]={$this->centre->id}")->assertJsonPath('data.*.code', ['C0012', 'D_100']);
        $this->getJson("/api/v1/clients?filter[category_id]={$this->category->id}")->assertJsonPath('data.*.code', ['C0012']);
        $this->getJson('/api/v1/clients?filter[status]=BLOCKED')->assertJsonPath('data.*.code', ['C0047']);
        $this->getJson('/api/v1/clients?filter[status]=active,blocked')->assertJsonPath('meta.total', 3);
    });

    it('sorts by code (default), name and last update, and paginates', function () {
        Client::where('code', 'C0047')->update(['updated_at' => now()->addMinute()]);

        $this->getJson('/api/v1/clients')->assertJsonPath('data.*.code', ['C0012', 'C0047', 'D_100']);
        $this->getJson('/api/v1/clients?sort=name')->assertJsonPath('data.*.code', ['D_100', 'C0047', 'C0012']);
        $this->getJson('/api/v1/clients?sort=-updated_at')->assertJsonPath('data.0.code', 'C0047');
        $this->getJson('/api/v1/clients?per_page=2&page=2')
            ->assertJsonPath('data.*.code', ['D_100'])
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2);
    });

    it('rejects unknown filters, sorts, statuses and identifiers', function (string $query) {
        expectProblem($this->getJson("/api/v1/clients?{$query}"), 422, 'VALIDATION_FAILED');
    })->with([
        'filter[nif]=1',
        'sort=phone',
        'filter[status]=ARCHIVED',
        'filter[region_id]=centre',
    ]);
});

describe('creation', function () {
    it('creates a client through the registry, code in upper case', function () {
        $this->actingAs($this->admin);

        $response = $this->postJson('/api/v1/clients', ($this->validPayload)(['code' => ' c0012 ']));

        $response->assertCreated()
            ->assertJsonPath('code', 'C0012')
            ->assertJsonPath('region.code', 'CENTRE')
            ->assertJsonPath('category.code', 'DETAILLANT')
            ->assertJsonPath('status', 'ACTIVE')
            ->assertJsonPath('accounts', []);

        $client = Client::where('code', 'C0012')->sole();
        expect($client->region_id)->toBe($this->centre->id)->and($client->next_region_id)->toBeNull();

        $entry = AuditLog::query()->where('subject_type', 'client')->where('subject_id', $client->id)->sole();
        expect($entry->log_name)->toBe('referentials')
            ->and($entry->event)->toBe('created')
            ->and($entry->causer_id)->toBe($this->admin->id)
            ->and($entry->properties['attributes']['code'])->toBe('C0012');
    });

    it('validates the fiche', function (array $overrides, string $field) {
        Client::factory()->create(['code' => 'C0047']);
        $inactive = Region::factory()->inactive()->create();
        $this->actingAs($this->admin);

        $payload = array_filter(($this->validPayload)($overrides), fn ($v) => $v !== '__missing__');
        if ($payload['region_id'] ?? null) {
            $payload['region_id'] = $payload['region_id'] === '__inactive__' ? $inactive->id : $payload['region_id'];
        }

        $this->postJson('/api/v1/clients', $payload)->assertJsonValidationErrors($field);
    })->with([
        'missing code' => [['code' => '__missing__'], 'code'],
        'code with spaces' => [['code' => 'C 12'], 'code'],
        'code with accents' => [['code' => 'CÉ12'], 'code'],
        'code too long' => [['code' => str_repeat('A', 31)], 'code'],
        'duplicate code, any case' => [['code' => 'c0047'], 'code'],
        'missing name' => [['name' => ''], 'name'],
        'missing region' => [['region_id' => '__missing__'], 'region_id'],
        'unknown region' => [['region_id' => 999999], 'region_id'],
        'inactive region' => [['region_id' => '__inactive__'], 'region_id'],
        'unknown category' => [['category_id' => 999999], 'category_id'],
        'latitude out of range' => [['lat' => 91], 'lat'],
        'longitude out of range' => [['lng' => -181], 'lng'],
        'latitude without longitude' => [['lng' => null], 'lng'],
        'invalid email' => [['email' => 'pas-un-email'], 'email'],
        'phone too long' => [['phone' => str_repeat('0', 31)], 'phone'],
    ]);

    it('explains a missing region and an inactive one in French', function () {
        $this->actingAs($this->admin);
        $inactive = Region::factory()->inactive()->create();

        $this->postJson('/api/v1/clients', ($this->validPayload)(['region_id' => $inactive->id]))
            ->assertJsonValidationErrors(['region_id' => __('clients.region_unavailable')]);
        $this->postJson('/api/v1/clients', ($this->validPayload)(['code' => 'C 1']))
            ->assertJsonValidationErrors(['code' => __('clients.code_format')]);
    });

    it('keeps a restricted manager inside their perimeter', function () {
        // Un rôle peut être ajusté sans modifier le code (§3.2) : ici l'ADV gère les clients.
        SpatieRole::findByName('adv')->givePermissionTo('client.manage');
        $adv = userWithRole(Role::Adv);
        RegionAssignment::factory()->adv()->create(['user_id' => $adv->id, 'region_id' => $this->est->id]);
        $outside = Client::factory()->create(['region_id' => $this->centre->id]);
        $this->actingAs($adv);

        $this->postJson('/api/v1/clients', ($this->validPayload)())->assertJsonValidationErrors('region_id');
        $this->postJson('/api/v1/clients', ($this->validPayload)(['region_id' => $this->est->id]))->assertCreated();
        expectProblem($this->patchJson("/api/v1/clients/{$outside->id}", ['name' => 'x']), 404, 'NOT_FOUND');
    });
});

describe('modification', function () {
    beforeEach(function () {
        $this->client = app(ClientRegistry::class)->create(($this->validPayload)(['code' => 'C0012']), null);
        $this->actingAs($this->admin);
    });

    it('updates the fiche partially and journals it', function () {
        $this->patchJson("/api/v1/clients/{$this->client->id}", ['name' => 'El Baraka SARL', 'phone2' => '0661223344', 'category_id' => null])
            ->assertOk()
            ->assertJsonPath('name', 'El Baraka SARL')
            ->assertJsonPath('phone2', '0661223344')
            ->assertJsonPath('category', null)
            ->assertJsonPath('trade_name', 'El Baraka')
            ->assertJsonPath('region.code', 'CENTRE');

        $entry = AuditLog::query()->where('subject_type', 'client')->where('subject_id', $this->client->id)->where('event', 'updated')->sole();
        expect($entry->causer_id)->toBe($this->admin->id)
            ->and($entry->properties['old']['name'])->toBe('Superette El Baraka')
            ->and($entry->properties['attributes']['name'])->toBe('El Baraka SARL');
    });

    it('never changes the code, but accepts it sent back unchanged', function () {
        $this->patchJson("/api/v1/clients/{$this->client->id}", ['code' => 'c0012', 'name' => 'Nouveau'])->assertOk();
        $this->patchJson("/api/v1/clients/{$this->client->id}", ['code' => 'C9999'])
            ->assertJsonValidationErrors(['code' => __('clients.code_immutable')]);

        expect($this->client->fresh()->code)->toBe('C0012');
    });

    it('validates partial updates', function (array $payload, string $field) {
        $this->patchJson("/api/v1/clients/{$this->client->id}", $payload)->assertJsonValidationErrors($field);
    })->with([
        'empty name' => [['name' => ''], 'name'],
        'null region' => [['region_id' => null], 'region_id'],
        'unknown region' => [['region_id' => 999999], 'region_id'],
        'latitude cleared alone' => [['lat' => null], 'lat'],
        'unknown status' => [['status' => 'ARCHIVED'], 'status'],
        'bad email' => [['email' => 'x'], 'email'],
    ]);

    it('accepts the current region even after it was deactivated', function () {
        Region::whereKey($this->centre->id)->update(['is_active' => false]);

        $this->patchJson("/api/v1/clients/{$this->client->id}", ['region_id' => $this->centre->id, 'name' => 'y'])->assertOk();
    });

    it('cancels a pending region change when the current region is sent back', function () {
        $this->patchJson("/api/v1/clients/{$this->client->id}", ['region_id' => $this->est->id])->assertJsonPath('pending_region_change.region.code', 'EST');
        $this->patchJson("/api/v1/clients/{$this->client->id}", ['region_id' => $this->centre->id])->assertJsonPath('pending_region_change', null);

        $entries = AuditLog::query()->where('subject_type', 'client')->where('subject_id', $this->client->id)->where('event', 'updated')->get();
        expect($entries)->toHaveCount(2)
            ->and($entries[0]->properties['attributes']['next_region_id'])->toBe($this->est->id);
    });
});

describe('status (EF-CLI-04, §3.5)', function () {
    beforeEach(function () {
        $this->client = Client::factory()->create(['region_id' => $this->centre->id]);
        $this->actingAs($this->admin);
    });

    it('requires a reason to block a client', function (array $payload) {
        $this->patchJson("/api/v1/clients/{$this->client->id}", ['status' => 'BLOCKED', ...$payload])
            ->assertJsonValidationErrors(['status_reason' => __('clients.status_reason_required')]);

        expect($this->client->fresh()->status)->toBe(ClientStatus::Active);
    })->with([
        'no reason' => [[]],
        'empty reason' => [['status_reason' => '']],
        'blank reason' => [['status_reason' => '   ']],
    ]);

    it('blocks then unblocks a client, with immediate effect and an audit trail', function () {
        $this->patchJson("/api/v1/clients/{$this->client->id}", ['status' => 'BLOCKED', 'status_reason' => ' Impayés '])
            ->assertOk()
            ->assertJsonPath('status', 'BLOCKED')
            ->assertJsonPath('status_label', 'Bloqué')
            ->assertJsonPath('status_reason', 'Impayés');

        $client = $this->client->fresh();
        expect($client->status_changed_by)->toBe($this->admin->id)->and($client->status_changed_at)->not->toBeNull();

        $this->patchJson("/api/v1/clients/{$this->client->id}", ['status_reason' => 'Retard de paiement'])
            ->assertOk()
            ->assertJsonPath('status', 'BLOCKED')
            ->assertJsonPath('status_reason', 'Retard de paiement');

        $this->patchJson("/api/v1/clients/{$this->client->id}", ['status' => 'ACTIVE'])
            ->assertOk()
            ->assertJsonPath('status', 'ACTIVE')
            ->assertJsonPath('status_reason', null);

        $changes = AuditLog::query()->where('subject_type', 'client')->where('subject_id', $this->client->id)
            ->where('event', 'updated')->orderBy('id')->get()
            ->map(fn (AuditLog $e) => array_intersect_key($e->properties['attributes'], array_flip(['status', 'status_reason'])))->all();
        expect($changes)->toBe([
            ['status' => 'BLOCKED', 'status_reason' => 'Impayés'],
            ['status_reason' => 'Retard de paiement'],
            ['status' => 'ACTIVE', 'status_reason' => null],
        ]);
    });

    it('deactivates the accounts of an inactive client and revokes their sessions', function () {
        $account = userWithRole(Role::Client, ['client_id' => $this->client->id]);
        $other = userWithRole(Role::Client, ['client_id' => Client::factory()->create()->id]);
        DB::table('sessions')->insert(['id' => 'phone', 'user_id' => $account->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);

        $this->patchJson("/api/v1/clients/{$this->client->id}", ['status' => 'INACTIVE', 'status_reason' => 'Cessation d\'activité'])
            ->assertOk()
            ->assertJsonPath('status', 'INACTIVE')
            ->assertJsonPath('accounts.0.is_active', false);

        expect($account->fresh()->is_active)->toBeFalse()
            ->and($other->fresh()->is_active)->toBeTrue()
            ->and(DB::table('sessions')->where('user_id', $account->id)->exists())->toBeFalse();

        // Le client inactif sort du périmètre du régional.
        $this->actingAs(($this->regionalOf)($this->centre));
        expectProblem($this->getJson("/api/v1/clients/{$this->client->id}"), 404, 'NOT_FOUND');
    });

    it('requires client.block on top of client.manage to change the status', function () {
        SpatieRole::findByName('admin')->revokePermissionTo('client.block');
        $this->actingAs(admin());

        expectProblem($this->patchJson("/api/v1/clients/{$this->client->id}", ['status' => 'BLOCKED']), 403, 'FORBIDDEN');
        expectProblem($this->patchJson("/api/v1/clients/{$this->client->id}", ['status_reason' => 'Motif']), 403, 'FORBIDDEN');

        // Le statut renvoyé tel quel n'est pas un changement.
        $this->patchJson("/api/v1/clients/{$this->client->id}", ['name' => 'Nouveau', 'status' => 'ACTIVE', 'status_reason' => null])
            ->assertOk()
            ->assertJsonPath('name', 'Nouveau');
        expect($this->client->fresh()->status)->toBe(ClientStatus::Active);
    });
});
