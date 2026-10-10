<?php

use App\Domain\Access\Role;
use App\Domain\Days\CutoffKind;
use App\Domain\Days\DayCalendar;
use App\Domain\Quotas\QuotaBook;
use App\Events\CutoffExtended;
use App\Events\DayClosed;
use App\Events\QuotaDraftsSaved;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionDay;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

// Prolongation d'une heure limite pour une région (RG-A05, §4.3 ; contrat
// du lot 3, §3).

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:30 à Alger : répartition jusqu'à 10:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    Event::fake([CutoffExtended::class, DayClosed::class, QuotaDraftsSaved::class]);
    $this->calendar = app(DayCalendar::class);
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    $this->day = $this->calendar->open(BusinessClock::parseDay('2026-10-08'), $this->adv);
    $this->extend = fn (Region $region, array $body, string $day = '2026-10-08') => $this->postJson("/api/v1/adv/days/{$day}/regions/{$region->id}/extend-cutoff", $body);
    $this->regionDay = fn (Region $region) => RegionDay::query()->where('business_day_id', $this->day->id)->where('region_id', $region->id)->sole();
    $this->actingAs($this->adv);
});

it('extends the distribution cutoff of one region and returns its region day', function () {
    app(QuotaBook::class)->saveDrafts($this->day, [
        ['region_id' => $this->centre->id, 'product_id' => Product::factory()->create()->id, 'quantity' => 500],
    ], $this->adv);

    ($this->extend)($this->centre, ['kind' => 'DISTRIBUTION', 'until' => '11:15'])
        ->assertOk()
        ->assertExactJson([
            'region' => ['id' => $this->centre->id, 'code' => 'CENTRE', 'name' => 'Centre'],
            'status' => 'QUOTA_DRAFT',
            'status_label' => 'Quotas en brouillon',
            'quota_version' => 0,
            'quota_published_at' => null,
            'quota_last_published_at' => null,
            'pending_drafts' => 1,
            'cutoffs' => ['distribution' => '11:15', 'ordering' => '13:00'],
            'extended' => ['distribution' => true, 'ordering' => false],
        ]);

    expect(BusinessClock::toLocal(($this->regionDay)($this->centre)->distribution_cutoff_override_at)->format('H:i'))->toBe('11:15')
        ->and(($this->regionDay)($this->est)->distribution_cutoff_override_at)->toBeNull();

    // La journée garde ses heures ; seule la région prolongée change.
    $this->getJson('/api/v1/adv/days/2026-10-08')
        ->assertOk()
        ->assertJsonPath('cutoffs.distribution', '10:00')
        ->assertJsonPath('regions.0.cutoffs.distribution', '11:15')
        ->assertJsonPath('regions.0.extended.distribution', true)
        ->assertJsonPath('regions.1.cutoffs.distribution', '10:00')
        ->assertJsonPath('regions.1.extended.distribution', false);
});

it('extends the ordering cutoff, again later if needed, and notifies through CutoffExtended', function () {
    ($this->extend)($this->est, ['kind' => 'ORDERING', 'until' => '14:00'])
        ->assertOk()
        ->assertJsonPath('cutoffs', ['distribution' => '10:00', 'ordering' => '14:00'])
        ->assertJsonPath('extended', ['distribution' => false, 'ordering' => true]);
    ($this->extend)($this->est, ['kind' => 'ORDERING', 'until' => '16:30'])
        ->assertOk()
        ->assertJsonPath('cutoffs.ordering', '16:30');

    $regionDayId = ($this->regionDay)($this->est)->id;
    Event::assertDispatchedTimes(CutoffExtended::class, 2);
    Event::assertDispatched(CutoffExtended::class, fn (CutoffExtended $event) => $event->regionDayId === $regionDayId
        && $event->kind === CutoffKind::Ordering
        && $event->actorId === $this->adv->id);
});

it('journals the extension in the days log (RG-A05)', function () {
    $admin = admin();
    $this->actingAs($admin);

    ($this->extend)($this->centre, ['kind' => 'DISTRIBUTION', 'until' => '11:15'])->assertOk();

    $entry = AuditLog::query()->where('log_name', 'days')->where('event', 'cutoff.extended')->sole();
    expect($entry->causer_id)->toBe($admin->id)
        ->and($entry->subject_id)->toBe(($this->regionDay)($this->centre)->id)
        ->and($entry->properties['day'])->toBe('2026-10-08')
        ->and($entry->properties['region'])->toBe('CENTRE')
        ->and($entry->properties['kind'])->toBe('DISTRIBUTION')
        ->and($entry->properties['old'])->toEqual(['distribution_cutoff_override_at' => '10:00'])
        ->and($entry->properties['attributes'])->toEqual(['distribution_cutoff_override_at' => '11:15']);
});

it('extends a cutoff for a region activated after the opening', function () {
    $sud = Region::factory()->create(['code' => 'SUD', 'name' => 'Sud']);

    ($this->extend)($sud, ['kind' => 'DISTRIBUTION', 'until' => '10:45'])
        ->assertOk()
        ->assertJsonPath('region.code', 'SUD')
        ->assertJsonPath('status', 'QUOTA_DRAFT')
        ->assertJsonPath('pending_drafts', 0)
        ->assertJsonPath('cutoffs.distribution', '10:45');
});

it('refuses an extension that is not later than the current cutoff', function () {
    ($this->extend)($this->centre, ['kind' => 'DISTRIBUTION', 'until' => '11:00'])->assertOk();

    foreach (['11:00', '10:30'] as $until) {
        $response = ($this->extend)($this->centre, ['kind' => 'DISTRIBUTION', 'until' => $until]);
        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonPath('errors.until.0', 'La prolongation doit être postérieure à l\'heure limite actuelle (11:00).');
    }
});

it('refuses an extension after the closing', function () {
    $response = ($this->extend)($this->centre, ['kind' => 'ORDERING', 'until' => '23:30']);

    expectProblem($response, 422, 'VALIDATION_FAILED');
    $response->assertJsonPath('errors.until.0', 'La prolongation ne peut pas dépasser l\'heure de clôture (23:00).');
    ($this->extend)($this->centre, ['kind' => 'ORDERING', 'until' => '23:00'])->assertOk()->assertJsonPath('cutoffs.ordering', '23:00');
});

it('refuses an extension that would already be over', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 11:00', 'Africa/Algiers'));

    $response = ($this->extend)($this->centre, ['kind' => 'DISTRIBUTION', 'until' => '10:30']);

    expectProblem($response, 422, 'VALIDATION_FAILED');
    $response->assertJsonPath('errors.until.0', 'La prolongation doit porter sur une heure à venir.');
    ($this->extend)($this->centre, ['kind' => 'DISTRIBUTION', 'until' => '11:30'])->assertOk();
});

it('validates the kind and the time', function (array $body, string $field, string $message) {
    $response = ($this->extend)($this->centre, $body);

    expectProblem($response, 422, 'VALIDATION_FAILED');
    $response->assertJsonPath("errors.{$field}.0", $message);
    expect(($this->regionDay)($this->centre)->only(['distribution_cutoff_override_at', 'ordering_cutoff_override_at']))
        ->toBe(['distribution_cutoff_override_at' => null, 'ordering_cutoff_override_at' => null]);
})->with([
    'missing kind' => [['until' => '11:00'], 'kind', 'Le champ heure limite à prolonger est obligatoire.'],
    'validation cutoff' => [['kind' => 'VALIDATION', 'until' => '16:00'], 'kind', 'Seules les heures limites de répartition et de commande se prolongent par région.'],
    'closing' => [['kind' => 'CLOSING', 'until' => '23:30'], 'kind', 'Seules les heures limites de répartition et de commande se prolongent par région.'],
    'lowercase kind' => [['kind' => 'distribution', 'until' => '11:00'], 'kind', 'Seules les heures limites de répartition et de commande se prolongent par région.'],
    'missing time' => [['kind' => 'DISTRIBUTION'], 'until', 'Le champ nouvelle heure limite est obligatoire.'],
    'malformed time' => [['kind' => 'DISTRIBUTION', 'until' => '11h15'], 'until', 'Heure attendue au format HH:MM (ex. 10:30).'],
    'hour out of range' => [['kind' => 'DISTRIBUTION', 'until' => '24:00'], 'until', 'Heure attendue au format HH:MM (ex. 10:30).'],
]);

it('refuses to extend on a closed day (409 DAY_NOT_OPEN) without creating a region day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 23:00', 'Africa/Algiers'));
    $this->artisan('ramymaak:close-days')->assertSuccessful();
    $sud = Region::factory()->create(['code' => 'SUD']);
    $this->travelTo(CarbonImmutable::parse('2026-10-08 23:10', 'Africa/Algiers'));

    expectProblem(($this->extend)($this->centre, ['kind' => 'ORDERING', 'until' => '22:00']), 409, 'DAY_NOT_OPEN');
    expectProblem(($this->extend)($sud, ['kind' => 'ORDERING', 'until' => '22:00']), 409, 'DAY_NOT_OPEN');
    expect($this->day->regionDays()->where('region_id', $sud->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'cutoff.extended')->exists())->toBeFalse();
    Event::assertNotDispatched(CutoffExtended::class);
});
