<?php

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Domain\Limits\LimitBook;
use App\Domain\Quotas\QuotaBook;
use App\Events\CutoffExtended;
use App\Events\DayClosed;
use App\Events\DistributionPublished;
use App\Events\LimitDraftsSaved;
use App\Events\QuotaDraftsSaved;
use App\Events\QuotasPublished;
use App\Models\Client;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionDay;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role as SpatieRole;

// Droits sur la répartition (contrat du lot 4, §2) : grille, saisie et
// « Reprendre J-1 », envoi réservés au régional (limit.view, limit.edit,
// limit.publish) ; lecture de la grille par l'ADV et l'Admin (limit.view) ;
// 403 avant 422 ; région hors périmètre à la date de la journée → 404.

beforeEach(function () {
    // Jeudi 8 octobre 2026, 08:30 à Alger (jour ouvré).
    $this->travelTo(CarbonImmutable::parse('2026-10-08 08:30', 'Africa/Algiers'));
    Event::fake([QuotasPublished::class, QuotaDraftsSaved::class, LimitDraftsSaved::class, DistributionPublished::class, CutoffExtended::class, DayClosed::class]);
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    $this->client = Client::factory()->create(['code' => 'C0012', 'region_id' => $this->centre->id]);
    $this->orange = Product::factory()->create(['sku' => 'P0042']);

    $this->calendar = app(DayCalendar::class);
    $this->quotas = app(QuotaBook::class);
    // Journée ouverte, quotas publiés pour toutes les régions.
    $this->open = function (string $date) {
        $day = $this->calendar->open(BusinessClock::parseDay($date), $this->adv);
        $this->quotas->saveDrafts($day, [
            ['region_id' => $this->centre->id, 'product_id' => $this->orange->id, 'quantity' => 500],
            ['region_id' => $this->est->id, 'product_id' => $this->orange->id, 'quantity' => 200],
        ], $this->adv);
        $this->quotas->publish($day, null, $this->adv);

        return $day;
    };

    $this->users = fn (string $role): User => match ($role) {
        'admin' => admin(),
        'adv' => userWithRole(Role::Adv),
        'regional' => tap(userWithRole(Role::Regional), fn (User $user) => RegionAssignment::factory()
            ->create(['user_id' => $user->id, 'region_id' => $this->centre->id])),
        'client' => userWithRole(Role::Client, ['client_id' => $this->client->id]),
        'none' => User::factory()->create(),
    };

    // Veille : une répartition envoyée au Centre, pour « Reprendre J-1 ».
    $previous = ($this->open)('2026-10-07');
    $previousCentre = RegionDay::query()->where('business_day_id', $previous->id)->where('region_id', $this->centre->id)->sole();
    app(LimitBook::class)->publish($previousCentre, ($this->users)('regional'));
    ($this->open)('2026-10-08');

    // Une requête valide par point d'accès.
    $this->call = fn (string $endpoint, ?Region $region = null) => match ($endpoint) {
        'grid' => $this->getJson('/api/v1/regional/days/2026-10-08/grid?region='.($region ?? $this->centre)->id),
        'back-office' => $this->getJson('/api/v1/adv/days/2026-10-08/regions/'.($region ?? $this->centre)->id.'/grid'),
        'limits' => $this->putJson('/api/v1/regional/days/2026-10-08/limits', [
            'region_id' => ($region ?? $this->centre)->id,
            'cells' => [['client_id' => $this->client->id, 'product_id' => $this->orange->id, 'limit' => 50]],
        ]),
        'copy' => $this->postJson('/api/v1/regional/days/2026-10-08/limits/copy-previous', ['region_id' => ($region ?? $this->centre)->id, 'mode' => 'FILL']),
        'publish' => $this->postJson('/api/v1/regional/days/2026-10-08/distribution/publish', ['region_id' => ($region ?? $this->centre)->id]),
    };
    $this->endpoints = ['grid', 'back-office', 'limits', 'copy', 'publish'];
    $this->forbid = fn (Role $role, Permission $permission) => SpatieRole::findByName($role->value)->revokePermissionTo($permission->value);
});

describe('rights matrix', function () {
    it('answers each role according to the matrix', function (string $role, array $allowed) {
        $this->actingAs(($this->users)($role));

        foreach ($this->endpoints as $endpoint) {
            $response = ($this->call)($endpoint);
            if (in_array($endpoint, $allowed, true)) {
                $response->assertOk();
            } else {
                expectProblem($response, 403, 'FORBIDDEN');
            }
        }
    })->with([
        'regional' => ['regional', ['grid', 'limits', 'copy', 'publish']],
        'ADV (lecture)' => ['adv', ['back-office']],
        'Admin (lecture)' => ['admin', ['back-office']],
        // Le client a limit.view pour ses propres bornes (lot 5), pas pour la grille.
        'client' => ['client', []],
        'sans rôle' => ['none', []],
    ]);

    it('requires an authenticated session', function (string $endpoint) {
        expectProblem(($this->call)($endpoint), 401, 'UNAUTHENTICATED');
    })->with(['grid', 'back-office', 'limits', 'copy', 'publish']);

    it('follows the permissions of the regional, not only the role', function (Permission $permission, array $forbidden) {
        ($this->forbid)(Role::Regional, $permission);
        $this->actingAs(($this->users)('regional'));

        foreach (['grid', 'limits', 'copy', 'publish'] as $endpoint) {
            $response = ($this->call)($endpoint);
            if (in_array($endpoint, $forbidden, true)) {
                expectProblem($response, 403, 'FORBIDDEN');
            } else {
                $response->assertOk();
            }
        }
    })->with([
        'limit.view' => [Permission::LimitView, ['grid']],
        'limit.edit' => [Permission::LimitEdit, ['limits', 'copy']],
        'limit.publish' => [Permission::LimitPublish, ['publish']],
    ]);

    it('derives can_edit and can_publish from the permissions of the regional', function (?Permission $permission, bool $edit, bool $publish) {
        if ($permission !== null) {
            ($this->forbid)(Role::Regional, $permission);
        }
        $this->actingAs(($this->users)('regional'));

        ($this->call)('grid')->assertOk()->assertJsonPath('can_edit', $edit)->assertJsonPath('can_publish', $publish);
    })->with([
        'all permissions' => [null, true, true],
        'without limit.edit' => [Permission::LimitEdit, false, true],
        'without limit.publish' => [Permission::LimitPublish, true, false],
    ]);

    it('requires limit.view for the back-office grid', function (Role $role) {
        ($this->forbid)($role, Permission::LimitView);
        $this->actingAs(userWithRole($role));

        expectProblem(($this->call)('back-office'), 403, 'FORBIDDEN');
    })->with([Role::Adv, Role::Admin]);

    it('keeps the back-office read-only: no edit, no sending', function (string $role) {
        $this->actingAs(($this->users)($role));

        ($this->call)('back-office')->assertOk()->assertJsonPath('can_edit', false)->assertJsonPath('can_publish', false);
    })->with(['adv', 'admin']);
});

describe('403 before 422', function () {
    it('refuses unauthorized users before validating their input', function (string $role) {
        $this->actingAs(($this->users)($role));

        expectProblem($this->getJson('/api/v1/regional/days/2026-10-08/grid'), 403, 'FORBIDDEN');
        expectProblem($this->getJson('/api/v1/regional/days/2026-10-08/grid?region=abc'), 403, 'FORBIDDEN');
        expectProblem($this->putJson('/api/v1/regional/days/2026-10-08/limits', []), 403, 'FORBIDDEN');
        expectProblem($this->putJson('/api/v1/regional/days/2026-10-08/limits', ['region_id' => 'x', 'cells' => 'y']), 403, 'FORBIDDEN');
        expectProblem($this->postJson('/api/v1/regional/days/2026-10-08/limits/copy-previous', ['mode' => 'X']), 403, 'FORBIDDEN');
        expectProblem($this->postJson('/api/v1/regional/days/2026-10-08/distribution/publish', []), 403, 'FORBIDDEN');
    })->with(['adv', 'admin', 'client']);

    it('refuses a regional without the permission before validating his input', function () {
        ($this->forbid)(Role::Regional, Permission::LimitEdit);
        ($this->forbid)(Role::Regional, Permission::LimitPublish);
        ($this->forbid)(Role::Regional, Permission::LimitView);
        $this->actingAs(($this->users)('regional'));

        expectProblem($this->getJson('/api/v1/regional/days/2026-10-08/grid'), 403, 'FORBIDDEN');
        expectProblem($this->putJson('/api/v1/regional/days/2026-10-08/limits', ['cells' => []]), 403, 'FORBIDDEN');
        expectProblem($this->postJson('/api/v1/regional/days/2026-10-08/limits/copy-previous', []), 403, 'FORBIDDEN');
        expectProblem($this->postJson('/api/v1/regional/days/2026-10-08/distribution/publish', []), 403, 'FORBIDDEN');
    });

    it('validates the region designation of an authorized regional', function () {
        $this->actingAs(($this->users)('regional'));

        $this->getJson('/api/v1/regional/days/2026-10-08/grid')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['region' => 'Le champ région est obligatoire.']);
        $this->getJson('/api/v1/regional/days/2026-10-08/grid?region=centre')->assertUnprocessable()->assertJsonValidationErrors('region');
        $this->getJson('/api/v1/regional/days/2026-10-08/grid?region[]=1')->assertUnprocessable()->assertJsonValidationErrors('region');

        foreach (['limits/copy-previous' => ['mode' => 'FILL'], 'distribution/publish' => []] as $path => $body) {
            $this->postJson("/api/v1/regional/days/2026-10-08/{$path}", $body)->assertUnprocessable()->assertJsonValidationErrors('region_id');
            $this->postJson("/api/v1/regional/days/2026-10-08/{$path}", [...$body, 'region_id' => (string) $this->centre->id])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['region_id' => 'Le champ région doit être un nombre entier.']);
        }
        $this->putJson('/api/v1/regional/days/2026-10-08/limits', [
            'cells' => [['client_id' => $this->client->id, 'product_id' => $this->orange->id, 'limit' => 5]],
        ])->assertUnprocessable()->assertJsonValidationErrors('region_id');
    });
});

describe('perimeter', function () {
    it('answers 404 to the regional of another region, for reading and writing', function () {
        $other = userWithRole(Role::Regional);
        RegionAssignment::factory()->create(['user_id' => $other->id, 'region_id' => $this->est->id]);
        $this->actingAs($other);

        foreach (['grid', 'limits', 'copy', 'publish'] as $endpoint) {
            expectProblem(($this->call)($endpoint), 404, 'NOT_FOUND');
        }
        // Avant même la validation du reste de la requête.
        expectProblem($this->putJson('/api/v1/regional/days/2026-10-08/limits', ['region_id' => $this->centre->id, 'cells' => 'x']), 404, 'NOT_FOUND');
        ($this->call)('grid', $this->est)->assertOk()->assertJsonPath('region.code', 'EST');
    });

    it('answers 404 for an unknown or inactive region', function () {
        $this->actingAs(($this->users)('regional'));
        $unknown = new Region(['code' => 'X']);
        $unknown->id = 999999;

        foreach (['grid', 'limits', 'copy', 'publish'] as $endpoint) {
            expectProblem(($this->call)($endpoint, $unknown), 404, 'NOT_FOUND');
        }

        $this->centre->update(['is_active' => false]);
        foreach (['grid', 'limits', 'copy', 'publish'] as $endpoint) {
            expectProblem(($this->call)($endpoint), 404, 'NOT_FOUND');
        }
        $this->actingAs($this->adv);
        expectProblem(($this->call)('back-office'), 404, 'NOT_FOUND');
        expectProblem(($this->call)('back-office', $unknown), 404, 'NOT_FOUND');
    });

    it('limits a restricted ADV to his regions on the day (RG-S05)', function () {
        $restricted = userWithRole(Role::Adv);
        RegionAssignment::factory()->adv()->create(['user_id' => $restricted->id, 'region_id' => $this->est->id]);
        $this->actingAs($restricted);

        expectProblem(($this->call)('back-office'), 404, 'NOT_FOUND');
        ($this->call)('back-office', $this->est)->assertOk()->assertJsonPath('region.code', 'EST');
    });

    it('follows a deputy within his period, judged on the date of the day (§3.4)', function () {
        $deputy = userWithRole(Role::Regional);
        RegionAssignment::factory()->deputy('2026-10-09', '2026-10-15')->create(['user_id' => $deputy->id, 'region_id' => $this->centre->id]);
        ($this->open)('2026-10-09');
        $this->actingAs($deputy);

        // Le 08/10, la suppléance n'a pas commencé ; elle couvre le 09/10, préparé la veille.
        expectProblem(($this->call)('grid'), 404, 'NOT_FOUND');
        $this->getJson("/api/v1/regional/days/2026-10-09/grid?region={$this->centre->id}")
            ->assertOk()
            ->assertJsonPath('day.day', '2026-10-09')
            ->assertJsonPath('can_edit', true);
        $this->putJson('/api/v1/regional/days/2026-10-09/limits', [
            'region_id' => $this->centre->id,
            'cells' => [['client_id' => $this->client->id, 'product_id' => $this->orange->id, 'limit' => 20]],
        ])->assertOk()->assertJsonPath('changed', 1);
    });

    it('answers 404 for a day that was not opened or a malformed date, before any rights check', function (string $role) {
        $this->actingAs(($this->users)($role));

        expectProblem($this->getJson("/api/v1/regional/days/2026-10-12/grid?region={$this->centre->id}"), 404, 'NOT_FOUND');
        expectProblem($this->getJson("/api/v1/regional/days/2026-02-30/grid?region={$this->centre->id}"), 404, 'NOT_FOUND');
        expectProblem($this->getJson("/api/v1/adv/days/2026-10-12/regions/{$this->centre->id}/grid"), 404, 'NOT_FOUND');
        expectProblem($this->putJson('/api/v1/regional/days/2026-10-12/limits', ['region_id' => $this->centre->id]), 404, 'NOT_FOUND');
        expectProblem($this->postJson('/api/v1/regional/days/08-10-2026/limits/copy-previous', []), 404, 'NOT_FOUND');
        expectProblem($this->postJson('/api/v1/regional/days/2026-10-12/distribution/publish', []), 404, 'NOT_FOUND');
    })->with(['regional', 'adv', 'client']);
});
