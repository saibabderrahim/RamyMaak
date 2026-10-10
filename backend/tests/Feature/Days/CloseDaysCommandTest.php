<?php

use App\Domain\Access\Role;
use App\Domain\Days\BusinessDayStatus;
use App\Domain\Days\DayCalendar;
use App\Domain\Days\RegionDayStatus;
use App\Events\CutoffExtended;
use App\Events\DayClosed;
use App\Models\AuditLog;
use App\Models\BusinessDay;
use App\Models\Region;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Event;

// Clôture automatique des journées (§4.4, §9.7) : ramymaak:close-days,
// lancée chaque minute, rejouable sans double effet.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 07:30', 'Africa/Algiers'));
    Event::fake([CutoffExtended::class, DayClosed::class]);
    $this->calendar = app(DayCalendar::class);
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->est = Region::factory()->create(['code' => 'EST']);
    $this->today = $this->calendar->open(BusinessClock::parseDay('2026-10-08'), $this->adv);
    $this->tomorrow = $this->calendar->open(BusinessClock::parseDay('2026-10-09'), $this->adv);
});

it('closes nothing before the closing time', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 22:59', 'Africa/Algiers'));

    $this->artisan('ramymaak:close-days')
        ->expectsOutputToContain('0 journée(s) clôturée(s).')
        ->assertSuccessful();

    expect($this->today->fresh()->status)->toBe(BusinessDayStatus::Open);
    Event::assertNotDispatched(DayClosed::class);
});

it('closes the day and its region days at 23:00 local time (22:00 UTC), once', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 23:00', 'Africa/Algiers'));

    $this->artisan('ramymaak:close-days')
        ->expectsOutputToContain('1 journée(s) clôturée(s).')
        ->assertSuccessful();
    // Rejouée : aucun double effet.
    $this->artisan('ramymaak:close-days')
        ->expectsOutputToContain('0 journée(s) clôturée(s).')
        ->assertSuccessful();

    $today = $this->today->fresh();
    expect($today->status)->toBe(BusinessDayStatus::Closed)
        ->and($today->closed_at->toIso8601String())->toBe('2026-10-08T22:00:00+00:00')
        ->and($today->regionDays()->pluck('status')->unique()->values()->all())->toEqual([RegionDayStatus::Closed])
        ->and($this->tomorrow->fresh()->status)->toBe(BusinessDayStatus::Open)
        ->and($this->tomorrow->regionDays()->pluck('status')->unique()->values()->all())->toEqual([RegionDayStatus::QuotaDraft]);
    Event::assertDispatchedTimes(DayClosed::class, 1);
    Event::assertDispatched(DayClosed::class, fn (DayClosed $event) => $event->businessDayId === $this->today->id);

    $entries = AuditLog::query()->where('log_name', 'days')->where('event', 'closed')->get();
    expect($entries)->toHaveCount(1)
        ->and($entries->first()->subject_id)->toBe($this->today->id)
        ->and($entries->first()->causer_id)->toBeNull();
});

it('catches up on days whose closing passed while the scheduler was stopped', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 08:00', 'Africa/Algiers'));

    $this->artisan('ramymaak:close-days')
        ->expectsOutputToContain('2 journée(s) clôturée(s).')
        ->assertSuccessful();

    expect(BusinessDay::query()->pluck('status')->unique()->values()->all())->toEqual([BusinessDayStatus::Closed]);
});

it('follows a closing time changed for the day', function () {
    $this->calendar->updateSchedule($this->today, ['CLOSING' => '21:30'], null, $this->adv);
    $this->travelTo(CarbonImmutable::parse('2026-10-08 21:30', 'Africa/Algiers'));

    $this->artisan('ramymaak:close-days')->expectsOutputToContain('1 journée(s) clôturée(s).')->assertSuccessful();

    expect($this->today->fresh()->status)->toBe(BusinessDayStatus::Closed);
});

it('shows a closed day as read only through the API', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 23:00', 'Africa/Algiers'));
    $this->artisan('ramymaak:close-days')->assertSuccessful();
    $this->actingAs($this->adv);

    $this->getJson('/api/v1/adv/days/2026-10-08')
        ->assertOk()
        ->assertJsonPath('status', 'CLOSED')
        ->assertJsonPath('closed_at', '2026-10-08T22:00:00+00:00')
        ->assertJsonPath('regions.*.status', ['CLOSED', 'CLOSED']);
    expectProblem($this->patchJson('/api/v1/adv/days/2026-10-08', ['cutoffs' => ['closing' => '23:30']]), 409, 'DAY_NOT_OPEN');
});

it('is scheduled every minute, without overlapping', function () {
    app(Kernel::class)->bootstrap();

    $events = collect(app(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'ramymaak:close-days'))
        ->values();

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});

it('records the opening, schedule, extension and closing in the days log', function () {
    $this->actingAs($this->adv);
    $this->postJson('/api/v1/adv/days', ['day' => '2026-10-11'])->assertCreated();
    $this->patchJson('/api/v1/adv/days/2026-10-11', ['cutoffs' => ['ordering' => '13:30']])->assertOk();
    $this->postJson("/api/v1/adv/days/2026-10-11/regions/{$this->centre->id}/extend-cutoff", ['kind' => 'ORDERING', 'until' => '14:00'])->assertOk();
    $this->travelTo(CarbonImmutable::parse('2026-10-11 23:00', 'Africa/Algiers'));
    // Le planificateur n'a pas d'utilisateur connecté : clôture sans auteur.
    forgetGuards();
    $this->artisan('ramymaak:close-days')->expectsOutputToContain('3 journée(s) clôturée(s).')->assertSuccessful();

    $day = BusinessDay::forDay('2026-10-11');
    $entries = AuditLog::query()
        ->where('log_name', 'days')
        ->where(fn ($query) => $query
            ->where(fn ($q) => $q->where('subject_type', 'business_day')->where('subject_id', $day->id))
            ->orWhere(fn ($q) => $q->where('subject_type', 'region_day')->whereIn('subject_id', $day->regionDays()->pluck('id'))))
        ->orderBy('id')
        ->get();

    expect($entries->pluck('event')->all())->toBe(['opened', 'schedule.updated', 'cutoff.extended', 'closed'])
        ->and($entries->pluck('description')->all())->toBe(['Journée ouverte', 'Heures limites de la journée modifiées', 'Heure limite prolongée', 'Journée clôturée'])
        ->and($entries->pluck('causer_id')->all())->toBe([$this->adv->id, $this->adv->id, $this->adv->id, null]);
});
