<?php

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Events\CutoffExtended;
use App\Events\DayClosed;
use App\Models\BusinessDay;
use App\Models\Client;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role as SpatieRole;

// Droits sur les journées (contrat du lot 3, §2 et §3) : lecture quota.view
// (ADV, Admin), ouverture et heures limites day.manage (ADV), prolongation
// day.extend-cutoff (ADV, Admin) dans le périmètre (RG-S05) ; droits
// contrôlés avant la validation (403 avant 422), région hors périmètre 404.

beforeEach(function () {
    // Jeudi 8 octobre 2026, 07:30 à Alger (jour ouvré).
    $this->travelTo(CarbonImmutable::parse('2026-10-08 07:30', 'Africa/Algiers'));
    Event::fake([CutoffExtended::class, DayClosed::class]);
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    $this->day = app(DayCalendar::class)->open(BusinessClock::parseDay('2026-10-08'), $this->adv);

    $this->users = fn (string $role): User => match ($role) {
        'admin' => admin(),
        'adv' => userWithRole(Role::Adv),
        'regional' => tap(userWithRole(Role::Regional), fn (User $user) => RegionAssignment::factory()
            ->create(['user_id' => $user->id, 'region_id' => $this->centre->id])),
        'client' => userWithRole(Role::Client, ['client_id' => Client::factory()->create(['region_id' => $this->centre->id])->id]),
    };

    // Une requête valide par point d'accès.
    $this->call = fn (string $endpoint) => match ($endpoint) {
        'index' => $this->getJson('/api/v1/adv/days'),
        'show' => $this->getJson('/api/v1/adv/days/2026-10-08'),
        'store' => $this->postJson('/api/v1/adv/days', ['day' => '2026-10-11']),
        'update' => $this->patchJson('/api/v1/adv/days/2026-10-08', ['cutoffs' => ['distribution' => '10:30']]),
        'extend' => $this->postJson("/api/v1/adv/days/2026-10-08/regions/{$this->centre->id}/extend-cutoff", ['kind' => 'DISTRIBUTION', 'until' => '11:15']),
    };
    $this->forbid = fn (Role $role, Permission $permission) => SpatieRole::findByName($role->value)->revokePermissionTo($permission->value);
    $this->grant = fn (Role $role, Permission $permission) => SpatieRole::findByName($role->value)->givePermissionTo($permission->value);
});

describe('rights matrix', function () {
    it('answers each role according to the matrix', function (string $role, string $endpoint, int $status) {
        $this->actingAs(($this->users)($role));

        $response = ($this->call)($endpoint);

        if ($status === 403) {
            expectProblem($response, 403, 'FORBIDDEN');
        } else {
            $response->assertStatus($status);
        }
    })->with([
        'adv reads the calendar' => ['adv', 'index', 200],
        'adv reads a day' => ['adv', 'show', 200],
        'adv opens a day' => ['adv', 'store', 201],
        'adv changes the cutoffs' => ['adv', 'update', 200],
        'adv extends a cutoff' => ['adv', 'extend', 200],
        'admin reads the calendar' => ['admin', 'index', 200],
        'admin reads a day' => ['admin', 'show', 200],
        'admin cannot open a day' => ['admin', 'store', 403],
        'admin cannot change the cutoffs' => ['admin', 'update', 403],
        'admin extends a cutoff' => ['admin', 'extend', 200],
        'regional cannot read the calendar' => ['regional', 'index', 403],
        'regional cannot read a day' => ['regional', 'show', 403],
        'regional cannot open a day' => ['regional', 'store', 403],
        'regional cannot change the cutoffs' => ['regional', 'update', 403],
        'regional cannot extend a cutoff' => ['regional', 'extend', 403],
        'client cannot read the calendar' => ['client', 'index', 403],
        'client cannot read a day' => ['client', 'show', 403],
        'client cannot open a day' => ['client', 'store', 403],
        'client cannot change the cutoffs' => ['client', 'update', 403],
        'client cannot extend a cutoff' => ['client', 'extend', 403],
    ]);

    it('requires an authenticated session', function (string $endpoint) {
        expectProblem(($this->call)($endpoint), 401, 'UNAUTHENTICATED');
    })->with(['index', 'show', 'store', 'update', 'extend']);

    it('follows the permissions, not only the role', function (Permission $permission, array $forbidden) {
        ($this->forbid)(Role::Adv, $permission);
        $this->actingAs(userWithRole(Role::Adv));

        foreach (['index', 'show', 'store', 'update', 'extend'] as $endpoint) {
            $response = ($this->call)($endpoint);
            if (in_array($endpoint, $forbidden, true)) {
                expectProblem($response, 403, 'FORBIDDEN');
            } else {
                $response->assertSuccessful();
            }
        }
    })->with([
        'quota.view' => [Permission::QuotaView, ['index', 'show']],
        'day.manage' => [Permission::DayManage, ['store', 'update']],
        'day.extend-cutoff' => [Permission::DayExtendCutoff, ['extend']],
    ]);

    it('lets the Admin open and change a day once granted day.manage', function () {
        ($this->grant)(Role::Admin, Permission::DayManage);
        $this->actingAs(admin());

        ($this->call)('store')->assertCreated();
        ($this->call)('update')->assertOk()->assertJsonPath('cutoffs.distribution', '10:30');
    });

    it('keeps the day routes closed to a regional even with quota.view (they read /regional/quotas)', function () {
        $regional = ($this->users)('regional');
        expect($regional->can(Permission::QuotaView->value))->toBeTrue();
        $this->actingAs($regional);

        expectProblem($this->getJson('/api/v1/adv/days'), 403, 'FORBIDDEN');
        expectProblem($this->getJson('/api/v1/adv/days/2026-10-08'), 403, 'FORBIDDEN');
    });
});

describe('403 before 422', function () {
    it('refuses unauthorized users before validating their input', function (string $role) {
        $this->actingAs(($this->users)($role));

        expectProblem($this->getJson('/api/v1/adv/days?from=nope&to=2027-12-31'), 403, 'FORBIDDEN');
        expectProblem($this->postJson('/api/v1/adv/days', ['day' => 'hier']), 403, 'FORBIDDEN');
        expectProblem($this->postJson('/api/v1/adv/days', []), 403, 'FORBIDDEN');
        expectProblem($this->patchJson('/api/v1/adv/days/2026-10-08', ['cutoffs' => ['distribution' => '25:99']]), 403, 'FORBIDDEN');
        expectProblem($this->postJson("/api/v1/adv/days/2026-10-08/regions/{$this->centre->id}/extend-cutoff", ['kind' => 'X']), 403, 'FORBIDDEN');
    })->with(['regional', 'client']);

    it('refuses the Admin before validating an opening or a schedule change', function () {
        $this->actingAs(admin());

        expectProblem($this->postJson('/api/v1/adv/days', ['day' => '2020-01-01']), 403, 'FORBIDDEN');
        expectProblem($this->patchJson('/api/v1/adv/days/2026-10-08', ['cutoffs' => ['closing' => 'minuit']]), 403, 'FORBIDDEN');
    });
});

describe('perimeter (RG-S05)', function () {
    beforeEach(function () {
        $this->restricted = userWithRole(Role::Adv);
        RegionAssignment::factory()->adv()->create(['user_id' => $this->restricted->id, 'region_id' => $this->est->id]);
    });

    it('shows a restricted ADV only the regions of their perimeter', function () {
        $this->actingAs($this->restricted);

        $this->getJson('/api/v1/adv/days/2026-10-08')->assertOk()->assertJsonPath('regions.*.region.code', ['EST']);
        $this->getJson('/api/v1/adv/days')->assertOk()->assertJsonPath('data.0.regions.*.region.code', ['EST']);
        $this->postJson('/api/v1/adv/days', ['day' => '2026-10-11'])->assertCreated()->assertJsonPath('regions.*.region.code', ['EST']);

        $this->actingAs($this->adv);
        $this->getJson('/api/v1/adv/days/2026-10-08')->assertOk()->assertJsonPath('regions.*.region.code', ['CENTRE', 'EST']);
    });

    it('answers 404 when a restricted ADV extends a region outside their perimeter, before validating', function () {
        $this->actingAs($this->restricted);

        expectProblem($this->postJson("/api/v1/adv/days/2026-10-08/regions/{$this->centre->id}/extend-cutoff", ['kind' => 'DISTRIBUTION', 'until' => '11:15']), 404, 'NOT_FOUND');
        expectProblem($this->postJson("/api/v1/adv/days/2026-10-08/regions/{$this->centre->id}/extend-cutoff", []), 404, 'NOT_FOUND');
        expect($this->day->regionDays()->whereNotNull('distribution_cutoff_override_at')->exists())->toBeFalse();

        $this->postJson("/api/v1/adv/days/2026-10-08/regions/{$this->est->id}/extend-cutoff", ['kind' => 'DISTRIBUTION', 'until' => '11:15'])
            ->assertOk()
            ->assertJsonPath('region.code', 'EST');
    });

    it('applies the perimeter of the day concerned', function () {
        // Affectation ADV à CENTRE à partir de dimanche seulement.
        RegionAssignment::factory()->adv()->create(['user_id' => $this->restricted->id, 'region_id' => $this->centre->id, 'starts_on' => '2026-10-11']);
        $this->actingAs($this->adv);
        $this->postJson('/api/v1/adv/days', ['day' => '2026-10-11'])->assertCreated();
        $this->actingAs($this->restricted);

        expectProblem($this->postJson("/api/v1/adv/days/2026-10-08/regions/{$this->centre->id}/extend-cutoff", ['kind' => 'DISTRIBUTION', 'until' => '11:15']), 404, 'NOT_FOUND');
        $this->postJson("/api/v1/adv/days/2026-10-11/regions/{$this->centre->id}/extend-cutoff", ['kind' => 'DISTRIBUTION', 'until' => '11:15'])->assertOk();
        $this->getJson('/api/v1/adv/days/2026-10-11')->assertOk()->assertJsonPath('regions.*.region.code', ['CENTRE', 'EST']);
    });

    it('answers 404 for an inactive or unknown region, and for a day not opened', function () {
        $old = Region::factory()->inactive()->create(['code' => 'OLD']);
        $this->actingAs(admin());

        expectProblem($this->postJson("/api/v1/adv/days/2026-10-08/regions/{$old->id}/extend-cutoff", ['kind' => 'DISTRIBUTION', 'until' => '11:15']), 404, 'NOT_FOUND');
        expectProblem($this->postJson('/api/v1/adv/days/2026-10-08/regions/999999/extend-cutoff', ['kind' => 'DISTRIBUTION', 'until' => '11:15']), 404, 'NOT_FOUND');
        expectProblem($this->postJson("/api/v1/adv/days/2026-10-12/regions/{$this->centre->id}/extend-cutoff", ['kind' => 'DISTRIBUTION', 'until' => '11:15']), 404, 'NOT_FOUND');
        expectProblem($this->getJson('/api/v1/adv/days/2026-10-12'), 404, 'NOT_FOUND');
        expectProblem($this->getJson('/api/v1/adv/days/2026-13-45'), 404, 'NOT_FOUND');
        expectProblem($this->patchJson('/api/v1/adv/days/08-10-2026', []), 404, 'NOT_FOUND');
        expect(BusinessDay::query()->count())->toBe(1);
    });
});
