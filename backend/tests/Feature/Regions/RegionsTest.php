<?php

use App\Domain\Access\Role;
use App\Domain\Referentials\ClientRegistry;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Depot;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Support\BusinessClock;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = admin();
    $this->depot = Depot::factory()->create(['code' => 'BLIDA', 'name' => 'Dépôt Blida']);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre', 'depot_id' => $this->depot->id]);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    $this->ouest = Region::factory()->create(['code' => 'OUEST', 'name' => 'Ouest']);
    $this->sud = Region::factory()->inactive()->create(['code' => 'SUD', 'name' => 'Sud']);
});

describe('rights and perimeter', function () {
    it('lets the Admin read every region, inactive ones included, on both paths', function (string $path) {
        $this->actingAs($this->admin);

        $this->getJson($path)
            ->assertOk()
            ->assertJsonPath('data.*.code', ['CENTRE', 'EST', 'OUEST', 'SUD'])
            ->assertJsonPath('meta.total', 4);

        $this->getJson("/api/v1/regions/{$this->sud->id}")->assertOk()->assertJsonPath('is_active', false);
    })->with(['/api/v1/regions', '/api/v1/admin/regions']);

    it('lets an ADV without ADV assignment read every active region (RG-S05)', function () {
        $this->actingAs(userWithRole(Role::Adv));

        $this->getJson('/api/v1/regions')->assertOk()->assertJsonPath('data.*.code', ['CENTRE', 'EST', 'OUEST']);
        $this->getJson('/api/v1/admin/regions')->assertOk()->assertJsonPath('data.*.code', ['CENTRE', 'EST', 'OUEST']);
        $this->getJson("/api/v1/regions/{$this->est->id}")->assertOk()->assertJsonPath('code', 'EST');
        expectProblem($this->getJson("/api/v1/regions/{$this->sud->id}"), 404, 'NOT_FOUND');
    });

    it('limits an ADV with ADV assignments to those regions', function () {
        $adv = userWithRole(Role::Adv);
        RegionAssignment::factory()->adv()->create(['user_id' => $adv->id, 'region_id' => $this->est->id]);
        $this->actingAs($adv);

        $this->getJson('/api/v1/regions')->assertOk()->assertJsonPath('data.*.code', ['EST']);
        expectProblem($this->getJson("/api/v1/regions/{$this->centre->id}"), 404, 'NOT_FOUND');
        expectProblem($this->getJson("/api/v1/admin/regions/{$this->centre->id}/assignments"), 404, 'NOT_FOUND');
    });

    it('limits a regional to the regions of their active assignments (RG-S01)', function () {
        $regional = userWithRole(Role::Regional);
        $today = BusinessClock::today();
        RegionAssignment::factory()->create(['user_id' => $regional->id, 'region_id' => $this->centre->id]);
        RegionAssignment::factory()->deputy($today->format('Y-m-d'), $today->addDays(3)->format('Y-m-d'))
            ->create(['user_id' => $regional->id, 'region_id' => $this->ouest->id]);
        RegionAssignment::factory()->deputy($today->subDays(10)->format('Y-m-d'), $today->subDay()->format('Y-m-d'))
            ->create(['user_id' => $regional->id, 'region_id' => $this->est->id]);
        $this->actingAs($regional);

        $this->getJson('/api/v1/regions')->assertOk()->assertJsonPath('data.*.code', ['CENTRE', 'OUEST']);
        $this->getJson("/api/v1/regions/{$this->ouest->id}")->assertOk();
        expectProblem($this->getJson("/api/v1/regions/{$this->est->id}"), 404, 'NOT_FOUND');
    });

    it('gives a regional without assignment an empty list', function () {
        $this->actingAs(userWithRole(Role::Regional));

        $this->getJson('/api/v1/regions')->assertOk()->assertJsonCount(0, 'data');
    });

    it('refuses the regions to client accounts', function () {
        $this->actingAs(userWithRole(Role::Client, ['client_id' => Client::factory()->create(['region_id' => $this->centre->id])->id]));

        expectProblem($this->getJson('/api/v1/regions'), 403, 'FORBIDDEN');
        expectProblem($this->getJson('/api/v1/admin/regions'), 403, 'FORBIDDEN');
        expectProblem($this->getJson("/api/v1/regions/{$this->centre->id}"), 403, 'FORBIDDEN');
    });

    it('reserves region writes to the Admin, rights checked before validation', function (Role $role) {
        $user = userWithRole($role);
        RegionAssignment::factory()->create(['user_id' => $user->id, 'region_id' => $this->centre->id, 'kind' => $role === Role::Adv ? 'ADV' : 'TITULAR']);
        $this->actingAs($user);

        expectProblem($this->postJson('/api/v1/admin/regions', ['code' => '!']), 403, 'FORBIDDEN');
        expectProblem($this->postJson('/api/v1/admin/regions', ['code' => 'NORD', 'name' => 'Nord']), 403, 'FORBIDDEN');
        expectProblem($this->patchJson("/api/v1/admin/regions/{$this->centre->id}", ['name' => '']), 403, 'FORBIDDEN');
        expectProblem($this->patchJson("/api/v1/admin/regions/{$this->centre->id}", ['name' => 'Centre 2']), 403, 'FORBIDDEN');
        expect($this->centre->fresh()->name)->toBe('Centre');
    })->with([Role::Adv, Role::Regional, Role::Client]);

    it('hides a region outside the perimeter of a manager with 404, even on writes', function () {
        // Un rôle peut être ajusté sans modifier le code (§3.2) : ici l'ADV reçoit la gestion des régions.
        Spatie\Permission\Models\Role::findByName('adv')->givePermissionTo('region.manage');
        $adv = userWithRole(Role::Adv);
        RegionAssignment::factory()->adv()->create(['user_id' => $adv->id, 'region_id' => $this->est->id]);
        $this->actingAs($adv);

        expectProblem($this->patchJson("/api/v1/admin/regions/{$this->centre->id}", ['name' => '']), 404, 'NOT_FOUND');
        $this->patchJson("/api/v1/admin/regions/{$this->est->id}", ['name' => 'Est algérien'])->assertOk();
    });

    it('returns a problem document for unknown regions', function () {
        $this->actingAs($this->admin);

        expectProblem($this->getJson('/api/v1/regions/999999'), 404, 'NOT_FOUND');
        expectProblem($this->patchJson('/api/v1/admin/regions/999999', ['name' => 'X']), 404, 'NOT_FOUND');
    });
});

describe('listing', function () {
    it('searches by code or name, filters on status, sorts and paginates', function () {
        $this->actingAs($this->admin);

        $this->getJson('/api/v1/admin/regions?filter[search]=ues')->assertJsonPath('data.*.code', ['OUEST']);
        $this->getJson('/api/v1/admin/regions?filter[search]=cent')->assertJsonPath('data.*.code', ['CENTRE']);
        $this->getJson('/api/v1/admin/regions?filter[is_active]=0')->assertJsonPath('data.*.code', ['SUD']);
        $this->getJson('/api/v1/admin/regions?filter[is_active]=1')->assertJsonPath('data.*.code', ['CENTRE', 'EST', 'OUEST']);
        $this->getJson('/api/v1/admin/regions?sort=-name')->assertJsonPath('data.*.name', ['Sud', 'Ouest', 'Est', 'Centre']);
        $this->getJson('/api/v1/admin/regions?per_page=2&page=2')
            ->assertJsonPath('data.*.code', ['OUEST', 'SUD'])
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 4);
    });

    it('searches a term containing a comma', function () {
        $this->actingAs($this->admin);
        Region::factory()->create(['code' => 'HP', 'name' => 'Hauts-Plateaux, Ouest']);

        $this->getJson('/api/v1/regions?filter[search]=plateaux, ouest')->assertOk()->assertJsonPath('data.*.code', ['HP']);
    });

    it('rejects unknown filters and sorts', function () {
        $this->actingAs($this->admin);

        expectProblem($this->getJson('/api/v1/regions?filter[depot_id]=1'), 422, 'VALIDATION_FAILED');
        expectProblem($this->getJson('/api/v1/regions?sort=clients_count'), 422, 'VALIDATION_FAILED');
    });

    it('describes a region: depot, titular of the day, current and upcoming deputies', function () {
        $this->actingAs($this->admin);
        $today = BusinessClock::today();
        $titular = userWithRole(Role::Regional, ['username' => 'k.saidi', 'name' => 'Karim Saïdi']);
        $current = RegionAssignment::factory()->create([
            'user_id' => $titular->id, 'region_id' => $this->centre->id, 'starts_on' => '2026-01-01',
        ]);
        // Titulaire suivant, pas encore en poste ; ancien titulaire révoqué.
        RegionAssignment::factory()->create([
            'region_id' => $this->centre->id, 'starts_on' => $today->addMonth()->format('Y-m-d'),
        ]);
        RegionAssignment::factory()->revoked()->create(['region_id' => $this->centre->id]);
        $ongoing = RegionAssignment::factory()->deputy($today->subDay()->format('Y-m-d'), $today->addDays(2)->format('Y-m-d'))
            ->create(['region_id' => $this->centre->id]);
        $upcoming = RegionAssignment::factory()->deputy($today->addDays(5)->format('Y-m-d'), $today->addDays(9)->format('Y-m-d'))
            ->create(['region_id' => $this->centre->id]);
        RegionAssignment::factory()->deputy($today->subDays(9)->format('Y-m-d'), $today->subDays(2)->format('Y-m-d'))
            ->create(['region_id' => $this->centre->id]);
        RegionAssignment::factory()->deputy()->revoked()->create(['region_id' => $this->centre->id]);
        RegionAssignment::factory()->adv()->create(['region_id' => $this->centre->id]);

        $this->getJson("/api/v1/regions/{$this->centre->id}")
            ->assertOk()
            ->assertJson([
                'id' => $this->centre->id,
                'code' => 'CENTRE',
                'name' => 'Centre',
                'is_active' => true,
                'depot' => ['id' => $this->depot->id, 'code' => 'BLIDA', 'name' => 'Dépôt Blida'],
                'titular' => [
                    'id' => $current->id,
                    'region_id' => $this->centre->id,
                    'kind' => 'TITULAR',
                    'kind_label' => 'Titulaire',
                    'user' => ['id' => $titular->id, 'username' => 'k.saidi', 'name' => 'Karim Saïdi'],
                    'starts_on' => '2026-01-01',
                    'ends_on' => null,
                    'is_active' => true,
                    'revoked_at' => null,
                    'revoked_by' => null,
                ],
                'clients_count' => 0,
            ])
            ->assertJsonPath('deputies.*.id', [$ongoing->id, $upcoming->id])
            ->assertJsonPath('deputies.*.is_active', [true, false])
            ->assertJsonPath('deputies.0.kind_label', 'Suppléant');

        $this->getJson("/api/v1/regions/{$this->est->id}")
            ->assertJsonPath('depot', null)
            ->assertJsonPath('titular', null)
            ->assertJsonPath('deputies', []);
    });

    it('counts the clients whose effective region today is the region (RG-S04)', function () {
        $this->actingAs($this->admin);
        $today = BusinessClock::today();
        Client::factory()->create(['region_id' => $this->centre->id]);
        Client::factory()->blocked()->create(['region_id' => $this->centre->id]);
        Client::factory()->inactive()->create(['region_id' => $this->centre->id]);
        Client::factory()->create(['region_id' => $this->est->id]);
        // Changement programmé pour demain : le client compte encore pour le Centre.
        $moving = Client::factory()->create(['region_id' => $this->centre->id]);
        app(ClientRegistry::class)->update($moving, ['region_id' => $this->est->id], $this->admin);
        // Changement échu, pas encore appliqué par la tâche de nuit : il compte pour l'Est.
        Client::factory()->create(['region_id' => $this->centre->id])
            ->forceFill(['next_region_id' => $this->est->id, 'region_change_on' => $today])->save();

        $this->getJson('/api/v1/regions')
            ->assertJsonPath('data.*.code', ['CENTRE', 'EST', 'OUEST', 'SUD'])
            ->assertJsonPath('data.*.clients_count', [3, 2, 0, 0]);
        $this->getJson("/api/v1/regions/{$this->centre->id}")->assertJsonPath('clients_count', 3);

        $this->travelTo($today->addDay()->setTime(8, 0));
        $this->flushSession();
        $this->getJson('/api/v1/regions')->assertJsonPath('data.*.clients_count', [2, 3, 0, 0]);
    });

    it('loads a page of regions with a fixed number of queries', function () {
        $this->actingAs($this->admin);
        config(['session.lottery' => [0, 100]]); // Pas de purge aléatoire des sessions pendant le comptage.
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/regions')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $populate = function (Region $region): void {
            RegionAssignment::factory()->create(['region_id' => $region->id]);
            RegionAssignment::factory()->deputy()->create(['region_id' => $region->id]);
            Client::factory()->count(2)->create(['region_id' => $region->id]);
        };

        $populate($this->centre);
        $queries(); // Chauffe : session, permissions mises en cache.
        $baseline = $queries();
        foreach ([$this->est, $this->ouest, $this->sud, ...Region::factory()->count(3)->create()] as $region) {
            $populate($region);
        }

        expect($queries())->toBe($baseline);
        $this->getJson('/api/v1/regions')->assertJsonPath('data.0.clients_count', 2);
    });
});

describe('creation and update', function () {
    it('refuses a code that only differs from an existing one by « - » or « _ » (quota import columns)', function () {
        $this->actingAs($this->admin);
        Region::factory()->create(['code' => 'SUD-EST', 'name' => 'Sud-Est']);

        $this->postJson('/api/v1/admin/regions', ['code' => 'sud_est', 'name' => 'Sud Est'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'Code trop proche de la région SUD-EST : les imports Excel ne distinguent pas « - » et « _ ».']);
        $this->postJson('/api/v1/admin/regions', ['code' => 'SUD-OUEST', 'name' => 'Sud-Ouest'])->assertCreated();
    });

    it('creates a region with an upper-case code and journals it', function () {
        $this->actingAs($this->admin);

        $this->postJson('/api/v1/admin/regions', ['code' => ' nord-1 ', 'name' => 'Nord', 'depot_id' => $this->depot->id])
            ->assertCreated()
            ->assertJson([
                'code' => 'NORD-1',
                'name' => 'Nord',
                'is_active' => true,
                'depot' => ['id' => $this->depot->id, 'code' => 'BLIDA'],
                'titular' => null,
                'deputies' => [],
                'clients_count' => 0,
            ]);

        $region = Region::query()->where('code', 'NORD-1')->sole();
        $entry = AuditLog::query()->where('subject_type', 'region')->where('subject_id', $region->id)->sole();
        expect($entry->log_name)->toBe('referentials')
            ->and($entry->event)->toBe('created')
            ->and($entry->description)->toBe('Région créée')
            ->and($entry->causer_id)->toBe($this->admin->id)
            ->and($entry->properties['attributes']['code'])->toBe('NORD-1');
    });

    it('creates an inactive region on request', function () {
        $this->actingAs($this->admin);

        $this->postJson('/api/v1/admin/regions', ['code' => 'NORD', 'name' => 'Nord', 'is_active' => false])
            ->assertCreated()
            ->assertJsonPath('is_active', false)
            ->assertJsonPath('depot', null);
    });

    it('validates region creation', function (array $payload, string $field) {
        $this->actingAs($this->admin);
        $closed = Depot::factory()->create(['is_active' => false]);
        if (($payload['depot_id'] ?? null) === 'closed') {
            $payload['depot_id'] = $closed->id;
        }

        $this->postJson('/api/v1/admin/regions', ['code' => 'NORD', 'name' => 'Nord', ...$payload])
            ->assertJsonValidationErrors($field);
    })->with([
        'missing code' => [['code' => ''], 'code'],
        'duplicate code, any case' => [['code' => 'centre'], 'code'],
        'code with spaces' => [['code' => 'HAUTS PLATEAUX'], 'code'],
        'code too short' => [['code' => 'N'], 'code'],
        'code too long' => [['code' => str_repeat('N', 21)], 'code'],
        'code with accents' => [['code' => 'RÉGION'], 'code'],
        'missing name' => [['name' => ''], 'name'],
        'unknown depot' => [['depot_id' => 999999], 'depot_id'],
        'inactive depot' => [['depot_id' => 'closed'], 'depot_id'],
        'invalid status' => [['is_active' => 'peut-être'], 'is_active'],
    ]);

    it('updates name, depot and status, and journals the change', function () {
        $this->actingAs($this->admin);
        $other = Depot::factory()->create(['code' => 'SETIF']);

        $this->patchJson("/api/v1/admin/regions/{$this->centre->id}", ['name' => 'Centre-Alger', 'depot_id' => $other->id])
            ->assertOk()
            ->assertJsonPath('name', 'Centre-Alger')
            ->assertJsonPath('code', 'CENTRE')
            ->assertJsonPath('depot.code', 'SETIF');

        $this->patchJson("/api/v1/admin/regions/{$this->centre->id}", ['depot_id' => null])->assertJsonPath('depot', null);
        $this->patchJson("/api/v1/admin/regions/{$this->sud->id}", ['is_active' => true])->assertJsonPath('is_active', true);

        $entry = AuditLog::query()->where('subject_type', 'region')->where('subject_id', $this->centre->id)
            ->where('event', 'updated')->orderBy('id')->first();
        expect($entry->description)->toBe('Région modifiée')
            ->and($entry->causer_id)->toBe($this->admin->id)
            ->and($entry->properties['old']['name'])->toBe('Centre')
            ->and($entry->properties['attributes']['name'])->toBe('Centre-Alger');
    });

    it('keeps the code: the key of the Excel files', function () {
        $this->actingAs($this->admin);

        $this->patchJson("/api/v1/admin/regions/{$this->centre->id}", ['code' => 'centre', 'name' => 'Centre'])->assertOk();
        $this->patchJson("/api/v1/admin/regions/{$this->centre->id}", ['code' => 'CENTRE-2'])
            ->assertJsonValidationErrors(['code' => __('regions.code_immutable')]);

        expect($this->centre->fresh()->code)->toBe('CENTRE');
    });

    it('refuses a deactivated depot but keeps the current one', function () {
        $this->actingAs($this->admin);
        $closed = Depot::factory()->create(['is_active' => false]);

        $this->patchJson("/api/v1/admin/regions/{$this->est->id}", ['depot_id' => $closed->id])
            ->assertJsonValidationErrors(['depot_id' => __('regions.depot_inactive')]);

        $this->depot->update(['is_active' => false]);
        $this->patchJson("/api/v1/admin/regions/{$this->centre->id}", ['name' => 'Centre', 'depot_id' => $this->depot->id])->assertOk();
    });

    it('validates region updates', function (array $payload, string $field) {
        $this->actingAs($this->admin);

        $this->patchJson("/api/v1/admin/regions/{$this->centre->id}", $payload)->assertJsonValidationErrors($field);
    })->with([
        'empty name' => [['name' => ''], 'name'],
        'unknown depot' => [['depot_id' => 999999], 'depot_id'],
        'empty status' => [['is_active' => null], 'is_active'],
    ]);
});

describe('deactivation', function () {
    it('refuses to deactivate a region that still has active or blocked clients', function (array $state) {
        $this->actingAs($this->admin);
        Client::factory()->create(['region_id' => $this->centre->id, ...$state]);

        $this->patchJson("/api/v1/admin/regions/{$this->centre->id}", ['is_active' => false])
            ->assertJsonValidationErrors(['is_active' => trans_choice('regions.has_clients', 1, ['count' => 1])]);

        expect($this->centre->fresh()->is_active)->toBeTrue();
    })->with([
        'active' => [['status' => 'ACTIVE']],
        'blocked' => [['status' => 'BLOCKED', 'status_reason' => 'Impayés']],
    ]);

    it('refuses to deactivate a region that clients are about to join (RG-S04)', function () {
        $this->actingAs($this->admin);
        $client = Client::factory()->create(['region_id' => $this->centre->id]);
        app(ClientRegistry::class)->update($client, ['region_id' => $this->est->id], $this->admin);

        Client::factory()->create(['region_id' => $this->est->id]);

        $this->patchJson("/api/v1/admin/regions/{$this->est->id}", ['is_active' => false])
            ->assertJsonValidationErrors(['is_active' => trans_choice('regions.has_clients', 2, ['count' => 2])]);
        $this->patchJson("/api/v1/admin/regions/{$this->centre->id}", ['is_active' => false])
            ->assertJsonValidationErrors('is_active');
    });

    it('deactivates a region whose clients are all inactive or already gone', function () {
        $this->actingAs($this->admin);
        Client::factory()->inactive()->create(['region_id' => $this->centre->id]);
        Client::factory()->create(['region_id' => $this->centre->id])
            ->forceFill(['next_region_id' => $this->est->id, 'region_change_on' => BusinessClock::today()])->save();

        $this->patchJson("/api/v1/admin/regions/{$this->centre->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false);
    });

    it('hides a deactivated region from the other roles', function () {
        $this->actingAs($this->admin);
        $this->patchJson("/api/v1/admin/regions/{$this->ouest->id}", ['is_active' => false])->assertOk();

        $this->actingAs(userWithRole(Role::Adv));
        $this->getJson('/api/v1/regions')->assertJsonPath('data.*.code', ['CENTRE', 'EST']);
    });
});
