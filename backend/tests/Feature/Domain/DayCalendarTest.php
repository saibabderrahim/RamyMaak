<?php

use App\Domain\Access\Role;
use App\Domain\Days\BusinessDayStatus;
use App\Domain\Days\CutoffKind;
use App\Domain\Days\DayCalendar;
use App\Domain\Days\RegionDayStatus;
use App\Domain\Days\RegionStaff;
use App\Domain\Settings\Settings;
use App\Events\CutoffExtended;
use App\Events\DayClosed;
use App\Http\Problems\ApiException;
use App\Models\AuditLog;
use App\Models\BusinessDay;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

// Calendrier des journées (§4.3, §4.4, §6.1, RG-A05).

beforeEach(function () {
    // Jeudi 8 octobre 2026, 09:00 à Alger.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00', 'Africa/Algiers'));
    $this->calendar = app(DayCalendar::class);
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->est = Region::factory()->create(['code' => 'EST']);
    Region::factory()->create(['code' => 'OLD', 'is_active' => false]);
    $this->local = fn ($moment) => BusinessClock::toLocal($moment)->format('H:i');
});

it('opens a day with the cutoffs of the settings and one region day per active region', function () {
    app(Settings::class)->update(['cutoffs' => ['ordering' => '12:30']], null);

    $day = $this->calendar->open(BusinessClock::parseDay('2026-10-08'), $this->adv);

    expect($day->status)->toBe(BusinessDayStatus::Open)
        ->and($day->dayKey())->toBe('2026-10-08')
        ->and(($this->local)($day->distribution_cutoff_at))->toBe('10:00')
        ->and(($this->local)($day->ordering_cutoff_at))->toBe('12:30')
        ->and(($this->local)($day->closing_at))->toBe('23:00')
        // Stockée en UTC : 23:00 à Alger = 22:00 UTC.
        ->and($day->closing_at->utc()->format('H:i'))->toBe('22:00')
        // Livraison au jour ouvré suivant : jeudi → dimanche (vendredi et samedi chômés).
        ->and($day->delivery_date->format('Y-m-d'))->toBe('2026-10-11')
        ->and($day->regionDays()->orderBy('region_id')->pluck('region_id')->all())->toBe([$this->centre->id, $this->est->id])
        ->and($day->regionDays()->first()->status)->toBe(RegionDayStatus::QuotaDraft);

    $entry = AuditLog::query()->where('log_name', 'days')->sole();
    expect($entry->event)->toBe('opened')->and($entry->causer_id)->toBe($this->adv->id);
});

it('refuses to open the same day twice', function () {
    $this->calendar->open(BusinessClock::parseDay('2026-10-08'), $this->adv);

    expect(fn () => $this->calendar->open(BusinessClock::parseDay('2026-10-08'), $this->adv))
        ->toThrow(fn (ApiException $e) => expect($e->errorCode->value)->toBe('INVALID_TRANSITION'));
});

it('changes the cutoffs of a day, in order, and journals them', function () {
    $day = $this->calendar->open(BusinessClock::parseDay('2026-10-08'), $this->adv);

    $this->calendar->updateSchedule($day, ['DISTRIBUTION' => '10:30', 'ORDERING' => '13:30'], null, $this->adv);

    $day->refresh();
    expect(($this->local)($day->distribution_cutoff_at))->toBe('10:30')
        ->and(($this->local)($day->ordering_cutoff_at))->toBe('13:30')
        ->and(($this->local)($day->validation_cutoff_at))->toBe('15:00');
    $entry = AuditLog::query()->where('event', 'schedule.updated')->sole();
    expect($entry->properties['old'])->toEqual(['distribution_cutoff_at' => '10:00', 'ordering_cutoff_at' => '13:00'])
        ->and($entry->properties['attributes'])->toEqual(['distribution_cutoff_at' => '10:30', 'ordering_cutoff_at' => '13:30']);

    expect(fn () => $this->calendar->updateSchedule($day, ['ORDERING' => '09:00'], null, $this->adv))
        ->toThrow(ValidationException::class);
    expect(fn () => $this->calendar->updateSchedule($day, ['DISTRIBUTION' => '06:00', 'ORDERING' => '07:00', 'VALIDATION' => '08:00', 'CLOSING' => '08:30'], null, $this->adv))
        ->toThrow(ValidationException::class);
});

it('extends a cutoff for one region only, later than the current one and before closing (RG-A05)', function () {
    Event::fake([CutoffExtended::class]);
    $day = $this->calendar->open(BusinessClock::parseDay('2026-10-08'), $this->adv);
    $centre = $this->calendar->regionDayFor($day, $this->centre);
    $est = $this->calendar->regionDayFor($day, $this->est);

    $this->calendar->extendCutoff($centre, CutoffKind::Distribution, '11:15', $this->adv);

    expect(($this->local)($centre->fresh()->effectiveCutoff(CutoffKind::Distribution)))->toBe('11:15')
        ->and(($this->local)($est->fresh()->effectiveCutoff(CutoffKind::Distribution)))->toBe('10:00');
    Event::assertDispatched(CutoffExtended::class, fn (CutoffExtended $e) => $e->regionDayId === $centre->id && $e->kind === CutoffKind::Distribution);
    expect(AuditLog::query()->where('event', 'cutoff.extended')->sole()->properties['attributes'])->toEqual(['distribution_cutoff_override_at' => '11:15']);

    expect(fn () => $this->calendar->extendCutoff($centre, CutoffKind::Distribution, '11:00', $this->adv))->toThrow(ValidationException::class)
        ->and(fn () => $this->calendar->extendCutoff($centre, CutoffKind::Ordering, '23:30', $this->adv))->toThrow(ValidationException::class)
        ->and(fn () => $this->calendar->extendCutoff($centre, CutoffKind::Closing, '23:30', $this->adv))->toThrow(ValidationException::class);
});

it('closes due days once, region days included (§4.4)', function () {
    Event::fake([DayClosed::class]);
    $day = $this->calendar->open(BusinessClock::parseDay('2026-10-08'), $this->adv);

    expect($this->calendar->closeDue())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-08 23:00', 'Africa/Algiers'));
    expect($this->calendar->closeDue())->toBe(1)
        ->and($this->calendar->closeDue())->toBe(0);

    $day->refresh();
    expect($day->status)->toBe(BusinessDayStatus::Closed)
        ->and($day->regionDays()->pluck('status')->unique()->all())->toEqual([RegionDayStatus::Closed]);
    Event::assertDispatchedTimes(DayClosed::class, 1);

    expect(fn () => $this->calendar->updateSchedule($day, ['CLOSING' => '23:30'], null, $this->adv))
        ->toThrow(fn (ApiException $e) => expect($e->errorCode->value)->toBe('DAY_NOT_OPEN'));
});

it('creates the region day of a region activated after the opening', function () {
    $day = $this->calendar->open(BusinessClock::parseDay('2026-10-08'), $this->adv);
    $sud = Region::factory()->create(['code' => 'SUD']);

    $this->calendar->syncRegions($day);

    expect($day->regionDays()->where('region_id', $sud->id)->exists())->toBeTrue()
        ->and(BusinessDay::query()->count())->toBe(1);
});

it('lists the regionals to notify: titular and deputies active that day', function () {
    $titular = userWithRole(Role::Regional);
    $deputy = userWithRole(Role::Regional);
    $later = userWithRole(Role::Regional);
    $inactive = userWithRole(Role::Regional, ['is_active' => false]);
    RegionAssignment::factory()->create(['user_id' => $titular->id, 'region_id' => $this->centre->id]);
    RegionAssignment::factory()->deputy()->create(['user_id' => $deputy->id, 'region_id' => $this->centre->id, 'ends_on' => '2026-10-10']);
    RegionAssignment::factory()->deputy()->create(['user_id' => $later->id, 'region_id' => $this->centre->id, 'starts_on' => '2026-10-09', 'ends_on' => '2026-10-10']);
    RegionAssignment::factory()->create(['user_id' => $inactive->id, 'region_id' => $this->centre->id, 'kind' => 'DEPUTY', 'ends_on' => '2026-10-10']);

    $ids = app(RegionStaff::class)->regionals($this->centre->id, BusinessClock::parseDay('2026-10-08'))->pluck('id')->all();

    expect($ids)->toBe([$titular->id, $deputy->id]);
});
