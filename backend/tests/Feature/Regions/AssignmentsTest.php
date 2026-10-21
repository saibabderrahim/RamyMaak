<?php

use App\Domain\Access\Role;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Support\BusinessClock;

beforeEach(function () {
    $this->admin = admin(['username' => 'boss', 'name' => 'Admin Principal']);
    $this->today = BusinessClock::today();
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->est = Region::factory()->create(['code' => 'EST']);
    $this->karim = userWithRole(Role::Regional, ['username' => 'k.saidi', 'name' => 'Karim Saïdi']);
    $this->nadia = userWithRole(Role::Regional, ['username' => 'n.belkacem', 'name' => 'Nadia Belkacem']);
    $this->day = fn (int $offset): string => $this->today->addDays($offset)->format('Y-m-d');
    $this->assign = fn (array $payload, ?Region $region = null) => $this->postJson(
        '/api/v1/admin/regions/'.($region ?? $this->centre)->id.'/assignments',
        $payload,
    );
});

describe('rights', function () {
    it('lets readers of the region see its assignments, within their perimeter', function () {
        RegionAssignment::factory()->create(['user_id' => $this->karim->id, 'region_id' => $this->centre->id]);

        $this->actingAs(userWithRole(Role::Adv));
        $this->getJson("/api/v1/admin/regions/{$this->centre->id}/assignments")->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($this->karim);
        $this->getJson("/api/v1/admin/regions/{$this->centre->id}/assignments")->assertOk()->assertJsonCount(1, 'data');
        expectProblem($this->getJson("/api/v1/admin/regions/{$this->est->id}/assignments"), 404, 'NOT_FOUND');

        $this->actingAs(userWithRole(Role::Client));
        expectProblem($this->getJson("/api/v1/admin/regions/{$this->centre->id}/assignments"), 403, 'FORBIDDEN');
    });

    it('reserves assignment changes to the Admin, rights checked before validation', function (Role $role) {
        $assignment = RegionAssignment::factory()->create(['user_id' => $this->karim->id, 'region_id' => $this->centre->id]);
        $this->actingAs($role === Role::Regional ? $this->karim : userWithRole($role));

        expectProblem(($this->assign)([]), 403, 'FORBIDDEN');
        expectProblem(($this->assign)(['user_id' => $this->nadia->id, 'kind' => 'DEPUTY', 'ends_on' => ($this->day)(3)]), 403, 'FORBIDDEN');
        expectProblem($this->deleteJson("/api/v1/admin/assignments/{$assignment->id}"), 403, 'FORBIDDEN');

        expect(RegionAssignment::query()->count())->toBe(1)
            ->and($assignment->fresh()->revoked_at)->toBeNull();
    })->with([Role::Adv, Role::Regional, Role::Client]);

    it('hides the assignments of a region outside the perimeter of a manager', function () {
        Spatie\Permission\Models\Role::findByName('adv')->givePermissionTo('region.manage');
        $adv = userWithRole(Role::Adv);
        RegionAssignment::factory()->adv()->create(['user_id' => $adv->id, 'region_id' => $this->est->id]);
        $assignment = RegionAssignment::factory()->create(['region_id' => $this->centre->id]);
        $this->actingAs($adv);

        expectProblem(($this->assign)([]), 404, 'NOT_FOUND');
        expectProblem($this->deleteJson("/api/v1/admin/assignments/{$assignment->id}"), 404, 'NOT_FOUND');
    });

    it('answers 404 for unknown regions and assignments', function () {
        $this->actingAs($this->admin);

        expectProblem($this->getJson('/api/v1/admin/regions/999999/assignments'), 404, 'NOT_FOUND');
        expectProblem($this->postJson('/api/v1/admin/regions/999999/assignments', []), 404, 'NOT_FOUND');
        expectProblem($this->deleteJson('/api/v1/admin/assignments/999999'), 404, 'NOT_FOUND');
    });
});

describe('history', function () {
    it('lists the full history, most recent first, and filters the active ones', function () {
        $this->actingAs($this->admin);
        $old = RegionAssignment::factory()->create([
            'user_id' => $this->nadia->id, 'region_id' => $this->centre->id,
            'starts_on' => '2026-01-01', 'revoked_at' => now()->subMonth(), 'revoked_by' => $this->admin->id,
        ]);
        $titular = RegionAssignment::factory()->create(['user_id' => $this->karim->id, 'region_id' => $this->centre->id]);
        $upcoming = RegionAssignment::factory()->deputy(($this->day)(3), ($this->day)(6))->create(['user_id' => $this->nadia->id, 'region_id' => $this->centre->id]);
        $ended = RegionAssignment::factory()->deputy(($this->day)(-9), ($this->day)(-2))->create(['user_id' => $this->nadia->id, 'region_id' => $this->centre->id]);
        RegionAssignment::factory()->create(['region_id' => $this->est->id]);

        $this->getJson("/api/v1/admin/regions/{$this->centre->id}/assignments")
            ->assertOk()
            ->assertJsonPath('data.*.id', [$upcoming->id, $ended->id, $titular->id, $old->id])
            ->assertJsonPath('data.3.revoked_by', ['id' => $this->admin->id, 'username' => 'boss', 'name' => 'Admin Principal'])
            ->assertJsonPath('data.3.is_active', false)
            ->assertJsonPath('data.0.kind', 'DEPUTY')
            ->assertJsonPath('data.0.ends_on', ($this->day)(6));

        $this->getJson("/api/v1/admin/regions/{$this->centre->id}/assignments?filter[active]=1")
            ->assertJsonPath('data.*.id', [$titular->id]);
        $this->getJson("/api/v1/admin/regions/{$this->centre->id}/assignments?filter[active]=0")
            ->assertJsonPath('data.*.id', [$upcoming->id, $ended->id, $old->id]);
    });

    it('rejects unknown filters and values', function () {
        $this->actingAs($this->admin);

        expectProblem($this->getJson("/api/v1/admin/regions/{$this->centre->id}/assignments?filter[active]=peut-etre"), 422, 'VALIDATION_FAILED');
        expectProblem($this->getJson("/api/v1/admin/regions/{$this->centre->id}/assignments?filter[kind]=ADV"), 422, 'VALIDATION_FAILED');
    });
});

describe('creation', function () {
    it('creates a titular starting today by default and journals it', function () {
        $this->actingAs($this->admin);

        $response = ($this->assign)(['user_id' => $this->karim->id, 'kind' => 'TITULAR'])
            ->assertCreated()
            ->assertJson([
                'region_id' => $this->centre->id,
                'kind' => 'TITULAR',
                'kind_label' => 'Titulaire',
                'user' => ['id' => $this->karim->id, 'username' => 'k.saidi', 'name' => 'Karim Saïdi'],
                'starts_on' => $this->today->format('Y-m-d'),
                'ends_on' => null,
                'is_active' => true,
                'revoked_at' => null,
                'revoked_by' => null,
            ]);
        expect($response->json('created_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/');

        $assignment = RegionAssignment::query()->findOrFail($response->json('id'));
        expect($assignment->created_by)->toBe($this->admin->id);

        $entry = AuditLog::query()->where('subject_type', 'region_assignment')->where('subject_id', $assignment->id)->sole();
        expect($entry->log_name)->toBe('referentials')
            ->and($entry->event)->toBe('created')
            ->and($entry->description)->toBe('Affectation créée')
            ->and($entry->causer_id)->toBe($this->admin->id)
            ->and($entry->properties['attributes']['user_id'])->toBe($this->karim->id)
            // Date calendaire journalisée telle quelle, pas l'instant UTC de la veille.
            ->and($entry->properties['attributes']['starts_on'])->toBe($this->today->format('Y-m-d'));

        $this->getJson("/api/v1/regions/{$this->centre->id}")->assertJsonPath('titular.id', $assignment->id);
    });

    it('creates an ADV assignment for an ADV account', function () {
        $this->actingAs($this->admin);
        $adv = userWithRole(Role::Adv);

        ($this->assign)(['user_id' => $adv->id, 'kind' => 'ADV', 'starts_on' => ($this->day)(0)])
            ->assertCreated()
            ->assertJsonPath('kind_label', 'ADV');
    });

    it('validates the assignment', function (Closure $payload, string $field, ?string $message = null) {
        $this->actingAs($this->admin);

        $response = ($this->assign)([
            'user_id' => $this->nadia->id,
            'kind' => 'DEPUTY',
            'starts_on' => ($this->day)(1),
            'ends_on' => ($this->day)(5),
            ...$payload->call($this),
        ]);

        $response->assertJsonValidationErrors($message === null ? $field : [$field => __($message, ['kind' => 'Suppléant', 'role' => 'Régional'])]);
        expect(RegionAssignment::query()->count())->toBe(0);
    })->with([
        'missing user' => [fn () => ['user_id' => null], 'user_id'],
        'unknown user' => [fn () => ['user_id' => 999999], 'user_id'],
        'inactive user' => [fn () => ['user_id' => userWithRole(Role::Regional, ['is_active' => false])->id], 'user_id', 'regions.user_inactive'],
        'ADV as deputy' => [fn () => ['user_id' => userWithRole(Role::Adv)->id], 'user_id', 'regions.user_role'],
        'client as deputy' => [fn () => ['user_id' => userWithRole(Role::Client)->id], 'user_id', 'regions.user_role'],
        'regional as ADV' => [fn () => ['kind' => 'ADV'], 'user_id'],
        'missing kind' => [fn () => ['kind' => null], 'kind'],
        'unknown kind' => [fn () => ['kind' => 'BOSS'], 'kind'],
        'deputy without end' => [fn () => ['ends_on' => null], 'ends_on', 'regions.deputy_needs_end'],
        'end before start' => [fn () => ['ends_on' => ($this->day)(0)], 'ends_on', 'regions.ends_before_start'],
        'start in the past' => [fn () => ['starts_on' => ($this->day)(-1)], 'starts_on', 'regions.starts_in_past'],
        'start badly formatted' => [fn () => ['starts_on' => '09/10/2026'], 'starts_on'],
        'impossible date' => [fn () => ['ends_on' => '2026-02-30'], 'ends_on'],
        'replace not boolean' => [fn () => ['replace' => 'oui'], 'replace'],
    ]);

    it('names the expected role in the message', function () {
        $this->actingAs($this->admin);

        ($this->assign)(['user_id' => $this->karim->id, 'kind' => 'ADV'])
            ->assertJsonValidationErrors(['user_id' => __('regions.user_role', ['kind' => 'ADV', 'role' => 'ADV'])]);
    });

    it('keeps a single titular at a time (H-02)', function () {
        $this->actingAs($this->admin);
        $current = RegionAssignment::factory()->create(['user_id' => $this->karim->id, 'region_id' => $this->centre->id]);

        ($this->assign)(['user_id' => $this->nadia->id, 'kind' => 'TITULAR'])
            ->assertJsonValidationErrors(['user_id' => trans_choice('regions.titular_taken', 1, ['name' => 'Karim Saïdi'])]);
        ($this->assign)(['user_id' => $this->nadia->id, 'kind' => 'TITULAR', 'starts_on' => ($this->day)(30), 'replace' => false])
            ->assertJsonValidationErrors('user_id');

        expect(RegionAssignment::query()->count())->toBe(1)
            ->and($current->fresh()->revoked_at)->toBeNull();
    });

    it('replaces the titular on request, in the same transaction', function () {
        $this->actingAs($this->admin);
        $this->freezeSecond();
        $current = RegionAssignment::factory()->create(['user_id' => $this->karim->id, 'region_id' => $this->centre->id]);

        $response = ($this->assign)(['user_id' => $this->nadia->id, 'kind' => 'TITULAR', 'replace' => true])->assertCreated();

        $current->refresh();
        expect($current->revoked_at?->toIso8601String())->toBe(now()->toIso8601String())
            ->and($current->revoked_by)->toBe($this->admin->id);

        $this->getJson("/api/v1/regions/{$this->centre->id}")
            ->assertJsonPath('titular.id', $response->json('id'))
            ->assertJsonPath('titular.user.name', 'Nadia Belkacem');

        $entry = AuditLog::query()->where('subject_type', 'region_assignment')->where('subject_id', $current->id)->where('event', 'revoked')->sole();
        expect($entry->causer_id)->toBe($this->admin->id)
            ->and($entry->properties['old']['revoked_at'])->toBeNull()
            ->and($entry->properties['attributes']['revoked_by'])->toBe($this->admin->id);
    });

    it('accepts a titular whose period does not overlap the current one', function () {
        $this->actingAs($this->admin);
        RegionAssignment::factory()->create(['user_id' => $this->karim->id, 'region_id' => $this->centre->id, 'ends_on' => ($this->day)(5)]);
        RegionAssignment::factory()->revoked()->create(['user_id' => $this->karim->id, 'region_id' => $this->centre->id, 'starts_on' => ($this->day)(0)]);

        ($this->assign)(['user_id' => $this->nadia->id, 'kind' => 'TITULAR', 'starts_on' => ($this->day)(5)])
            ->assertJsonValidationErrors('user_id');
        ($this->assign)(['user_id' => $this->nadia->id, 'kind' => 'TITULAR', 'starts_on' => ($this->day)(6)])
            ->assertCreated()
            ->assertJsonPath('is_active', false);

        // Le titulaire du jour reste l'actuel.
        $this->getJson("/api/v1/regions/{$this->centre->id}")->assertJsonPath('titular.user.id', $this->karim->id);
    });

    it('refuses a bounded titular that overlaps an upcoming one', function () {
        $this->actingAs($this->admin);
        RegionAssignment::factory()->create(['user_id' => $this->karim->id, 'region_id' => $this->centre->id, 'starts_on' => ($this->day)(10)]);

        ($this->assign)(['user_id' => $this->nadia->id, 'kind' => 'TITULAR', 'ends_on' => ($this->day)(9)])->assertCreated();
        ($this->assign)(['user_id' => userWithRole(Role::Regional)->id, 'kind' => 'TITULAR', 'starts_on' => ($this->day)(9), 'ends_on' => ($this->day)(10), 'replace' => false])
            ->assertJsonValidationErrors(['user_id' => trans_choice('regions.titular_taken', 2, ['name' => 'Nadia Belkacem, Karim Saïdi'])]);
    });

    it('allows several deputies over the same period (§3.4)', function () {
        $this->actingAs($this->admin);
        $payload = ['kind' => 'DEPUTY', 'starts_on' => ($this->day)(0), 'ends_on' => ($this->day)(7)];

        ($this->assign)(['user_id' => $this->karim->id, ...$payload])->assertCreated();
        ($this->assign)(['user_id' => $this->nadia->id, ...$payload])->assertCreated();

        $this->getJson("/api/v1/regions/{$this->centre->id}")->assertJsonCount(2, 'deputies');
    });

    it('refuses a duplicate over overlapping periods, even with replace', function () {
        $this->actingAs($this->admin);
        RegionAssignment::factory()->deputy(($this->day)(0), ($this->day)(7))->create(['user_id' => $this->nadia->id, 'region_id' => $this->centre->id]);
        RegionAssignment::factory()->create(['user_id' => $this->karim->id, 'region_id' => $this->centre->id]);

        ($this->assign)(['user_id' => $this->nadia->id, 'kind' => 'DEPUTY', 'starts_on' => ($this->day)(7), 'ends_on' => ($this->day)(9)])
            ->assertJsonValidationErrors(['user_id' => __('regions.duplicate', ['kind' => 'Suppléant'])]);
        ($this->assign)(['user_id' => $this->karim->id, 'kind' => 'TITULAR', 'replace' => true])
            ->assertJsonValidationErrors(['user_id' => __('regions.duplicate', ['kind' => 'Titulaire'])]);

        // Période suivante, autre région : acceptées.
        ($this->assign)(['user_id' => $this->nadia->id, 'kind' => 'DEPUTY', 'starts_on' => ($this->day)(8), 'ends_on' => ($this->day)(9)])->assertCreated();
        ($this->assign)(['user_id' => $this->nadia->id, 'kind' => 'DEPUTY', 'starts_on' => ($this->day)(0), 'ends_on' => ($this->day)(7)], $this->est)->assertCreated();
        expect(RegionAssignment::query()->whereNull('revoked_at')->count())->toBe(4);
    });

    it('gives a deputy the region only during the period', function () {
        $this->actingAs($this->admin);
        ($this->assign)(['user_id' => $this->nadia->id, 'kind' => 'DEPUTY', 'starts_on' => ($this->day)(2), 'ends_on' => ($this->day)(4)])
            ->assertCreated()
            ->assertJsonPath('is_active', false);

        $this->actingAs($this->nadia);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('perimeter.region_ids', []);
        $this->getJson('/api/v1/regions')->assertJsonCount(0, 'data');

        $this->travelTo($this->today->addDays(2)->setTime(9, 0));
        $this->getJson('/api/v1/auth/me')->assertJsonPath('perimeter.region_ids', [$this->centre->id]);
        $this->getJson('/api/v1/regions')->assertJsonPath('data.*.code', ['CENTRE']);

        $this->travelTo($this->today->addDays(5)->setTime(9, 0));
        $this->getJson('/api/v1/auth/me')->assertJsonPath('perimeter.region_ids', []);
    });
});

describe('revocation', function () {
    it('ends an assignment immediately and journals it (RG-S03)', function () {
        $this->freezeSecond();
        $assignment = RegionAssignment::factory()->create(['user_id' => $this->karim->id, 'region_id' => $this->centre->id]);
        Client::factory()->create(['code' => 'C0012', 'region_id' => $this->centre->id]);

        $this->actingAs($this->karim);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('perimeter', ['all_regions' => false, 'region_ids' => [$this->centre->id]]);
        $this->getJson('/api/v1/regions')->assertJsonPath('data.*.code', ['CENTRE']);

        $this->actingAs($this->admin);
        $this->deleteJson("/api/v1/admin/assignments/{$assignment->id}")
            ->assertOk()
            ->assertJson([
                'id' => $assignment->id,
                'is_active' => false,
                'revoked_at' => now()->toIso8601String(),
                'revoked_by' => ['id' => $this->admin->id, 'username' => 'boss', 'name' => 'Admin Principal'],
            ]);

        $this->actingAs($this->karim);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('perimeter', ['all_regions' => false, 'region_ids' => []]);
        $this->getJson('/api/v1/regions')->assertJsonCount(0, 'data');
        expectProblem($this->getJson("/api/v1/regions/{$this->centre->id}"), 404, 'NOT_FOUND');

        $entry = AuditLog::query()->where('subject_type', 'region_assignment')->where('subject_id', $assignment->id)->where('event', 'revoked')->sole();
        expect($entry->description)->toBe('Affectation révoquée')
            ->and($entry->causer_id)->toBe($this->admin->id)
            ->and($entry->properties['old']['revoked_at'])->toBeNull()
            ->and($entry->properties['attributes']['revoked_by'])->toBe($this->admin->id)
            ->and($entry->properties['attributes']['revoked_at'])->not->toBeNull();
    });

    it('refuses to revoke twice', function () {
        $this->actingAs($this->admin);
        $assignment = RegionAssignment::factory()->revoked()->create(['region_id' => $this->centre->id]);
        $revokedAt = $assignment->revoked_at->toIso8601String();

        expectProblem($this->deleteJson("/api/v1/admin/assignments/{$assignment->id}"), 409, 'INVALID_TRANSITION');
        expect($assignment->fresh()->revoked_at->toIso8601String())->toBe($revokedAt);
    });

    it('removes a deputy from the region as soon as it is revoked', function () {
        $this->actingAs($this->admin);
        $deputy = RegionAssignment::factory()->deputy()->create(['user_id' => $this->nadia->id, 'region_id' => $this->centre->id]);
        $this->getJson("/api/v1/regions/{$this->centre->id}")->assertJsonPath('deputies.*.id', [$deputy->id]);

        $this->deleteJson("/api/v1/admin/assignments/{$deputy->id}")->assertOk();

        $this->getJson("/api/v1/regions/{$this->centre->id}")->assertJsonPath('deputies', []);
        $this->getJson("/api/v1/admin/regions/{$this->centre->id}/assignments?filter[active]=1")->assertJsonCount(0, 'data');
    });
});

describe('profile perimeter', function () {
    it('exposes the perimeter of each role on /auth/me', function () {
        $adv = userWithRole(Role::Adv);
        $restrictedAdv = userWithRole(Role::Adv);
        RegionAssignment::factory()->adv()->create(['user_id' => $restrictedAdv->id, 'region_id' => $this->est->id]);
        RegionAssignment::factory()->create(['user_id' => $this->karim->id, 'region_id' => $this->est->id]);
        RegionAssignment::factory()->deputy()->create(['user_id' => $this->karim->id, 'region_id' => $this->centre->id]);
        $client = userWithRole(Role::Client, ['client_id' => Client::factory()->create(['region_id' => $this->est->id])->id]);
        $expected = [
            [$this->admin, true, []],
            [$adv, true, []],
            [$restrictedAdv, false, [$this->est->id]],
            [$this->karim, false, [$this->centre->id, $this->est->id]],
            [$this->nadia, false, []],
            [$client, false, [$this->est->id]],
        ];

        foreach ($expected as [$user, $all, $regionIds]) {
            $this->actingAs($user);
            $this->getJson('/api/v1/auth/me')
                ->assertOk()
                ->assertJsonPath('perimeter', ['all_regions' => $all, 'region_ids' => $regionIds]);
        }
    });
});
