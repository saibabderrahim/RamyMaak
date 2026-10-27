<?php

use App\Domain\Access\Role;
use App\Domain\Days\DayCalendar;
use App\Domain\Quotas\QuotaBook;
use App\Domain\Reminders\Reminder;
use App\Domain\Reminders\Reminders;
use App\Domain\Reminders\SendReminder;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\RegionDay;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Queue;

// Commande ramymaak:send-reminders (contrat du lot 8, §3) : lancée chaque
// minute, une tâche de fond par rappel dû (EF-NOT-06), rejouable.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 07:30', 'Africa/Algiers'));
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    $this->regional = userWithRole(Role::Regional);
    RegionAssignment::factory()->create(['region_id' => $this->centre->id, 'user_id' => $this->regional->id]);
    $this->calendar = app(DayCalendar::class);
    $this->day = $this->calendar->open(BusinessClock::parseDay('2026-10-08'), $this->adv);
    $quotas = app(QuotaBook::class);
    $quotas->saveDrafts($this->day, [['region_id' => $this->centre->id, 'product_id' => Product::factory()->create()->id, 'quantity' => 500]], $this->adv);
    $quotas->publish($this->day, [$this->centre->id], $this->adv);
    $this->regionDay = RegionDay::query()->where('business_day_id', $this->day->id)->where('region_id', $this->centre->id)->sole();
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30', 'Africa/Algiers'));
    $this->reminders = fn () => DatabaseNotification::query()->whereIn('type', ['DISTRIBUTION_REMINDER', 'DISTRIBUTION_LATE']);
});

it('is scheduled every minute with a short lock', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'ramymaak:send-reminders'))
        ->values();

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue()
        // Verrou court : un arrêt brutal du planificateur ne bloque pas les rappels 24 h.
        ->and($events->first()->expiresAt)->toBe(5);
});

it('queues one job per due reminder and sends nothing itself (EF-NOT-06)', function () {
    Queue::fake();

    $this->artisan('ramymaak:send-reminders')
        ->expectsOutputToContain('1 rappel(s) programmé(s).')
        ->assertSuccessful();

    Queue::assertPushed(SendReminder::class, 1);
    Queue::assertPushed(SendReminder::class, fn (SendReminder $job) => $job->regionDayId === $this->regionDay->id && $job->reminder === Reminder::Distribution);
    expect(($this->reminders)()->count())->toBe(0);
});

it('rechecks the window when the job runs: a job delayed past the cutoff sends nothing', function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:59', 'Africa/Algiers'));
    $this->artisan('ramymaak:send-reminders')->assertSuccessful();
    $job = Queue::pushed(SendReminder::class)->sole();

    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00', 'Africa/Algiers'));
    $job->handle(app(Reminders::class));

    expect(($this->reminders)()->count())->toBe(0);
});

it('is replayable without double sending (§9.7)', function () {
    $this->artisan('ramymaak:send-reminders')->assertSuccessful();
    $this->artisan('ramymaak:send-reminders')->assertSuccessful();
    (new SendReminder($this->regionDay->id, Reminder::Distribution))->handle(app(Reminders::class));

    expect(($this->reminders)()->count())->toBe(1);
});

it('skips closed days and inactive regions', function () {
    $this->est->forceFill(['is_active' => false])->save();
    $this->centre->forceFill(['is_active' => false])->save();

    $this->artisan('ramymaak:send-reminders')
        ->expectsOutputToContain('0 rappel(s) programmé(s).')
        ->assertSuccessful();

    $this->centre->forceFill(['is_active' => true])->save();
    $this->calendar->close($this->day);
    $this->artisan('ramymaak:send-reminders')
        ->expectsOutputToContain('0 rappel(s) programmé(s).')
        ->assertSuccessful();
    // Tâche déjà programmée avant la clôture : rien non plus.
    (new SendReminder($this->regionDay->id, Reminder::Distribution))->handle(app(Reminders::class));

    expect(($this->reminders)()->count())->toBe(0);
});
