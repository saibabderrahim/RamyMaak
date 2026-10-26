<?php

use App\Domain\Access\Role;
use App\Domain\Days\CutoffKind;
use App\Domain\Days\DayCalendar;
use App\Domain\Settings\Settings;
use App\Events\CutoffExtended;
use App\Events\DayClosed;
use App\Models\AuditLog;
use App\Models\BusinessDay;
use App\Models\Region;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

// Ouverture d'une journée et heures limites (EF-ADV-01, §4.2, §4.3 ;
// contrat du lot 3, §3).

beforeEach(function () {
    // Jeudi 8 octobre 2026, 07:30 à Alger (jour ouvré).
    $this->travelTo(CarbonImmutable::parse('2026-10-08 07:30', 'Africa/Algiers'));
    Event::fake([CutoffExtended::class, DayClosed::class]);
    $this->calendar = app(DayCalendar::class);
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE']);
    $this->est = Region::factory()->create(['code' => 'EST']);
    Region::factory()->inactive()->create(['code' => 'OLD']);
    $this->actingAs($this->adv);
});

describe('POST /adv/days', function () {
    it('opens a day with the cutoffs of the settings and the regions in draft', function () {
        app(Settings::class)->update(['cutoffs' => ['ordering' => '12:30']], null);

        $this->postJson('/api/v1/adv/days', ['day' => '2026-10-08'])
            ->assertCreated()
            ->assertJsonPath('day', '2026-10-08')
            ->assertJsonPath('status', 'OPEN')
            ->assertJsonPath('is_working_day', true)
            ->assertJsonPath('delivery_date', '2026-10-11')
            ->assertJsonPath('cutoffs', ['distribution' => '10:00', 'ordering' => '12:30', 'validation' => '15:00', 'closing' => '23:00'])
            ->assertJsonPath('cutoffs_at.ordering', '2026-10-08T11:30:00+00:00')
            ->assertJsonPath('regions.*.region.code', ['CENTRE', 'EST'])
            ->assertJsonPath('regions.*.status', ['QUOTA_DRAFT', 'QUOTA_DRAFT'])
            ->assertJsonPath('created_by.id', $this->adv->id);

        $day = BusinessDay::forDay('2026-10-08');
        expect($day->regionDays()->count())->toBe(2);

        $entry = AuditLog::query()->where('log_name', 'days')->sole();
        expect($entry->event)->toBe('opened')
            ->and($entry->causer_id)->toBe($this->adv->id)
            ->and($entry->subject_id)->toBe($day->id)
            ->and($entry->properties['attributes']['day'])->toBe('2026-10-08');
    });

    it('opens a non-working day (the screen asks for confirmation)', function () {
        $this->postJson('/api/v1/adv/days', ['day' => '2026-10-09'])
            ->assertCreated()
            ->assertJsonPath('weekday', 5)
            ->assertJsonPath('is_working_day', false)
            // Livraison au jour ouvré suivant : vendredi → dimanche.
            ->assertJsonPath('delivery_date', '2026-10-11');
    });

    it('opens from today up to today + 30', function (string $day) {
        $this->postJson('/api/v1/adv/days', ['day' => $day])->assertCreated()->assertJsonPath('day', $day);
    })->with(['2026-10-08', '2026-11-07']);

    it('follows the business day, not the UTC date', function () {
        // 00:30 à Alger le 9 octobre = 23:30 UTC le 8 : aujourd'hui est le 9.
        $this->travelTo(CarbonImmutable::parse('2026-10-09 00:30', 'Africa/Algiers'));

        expectProblem($this->postJson('/api/v1/adv/days', ['day' => '2026-10-08']), 422, 'VALIDATION_FAILED');
        $this->postJson('/api/v1/adv/days', ['day' => '2026-10-09'])->assertCreated();
    });

    it('refuses an invalid day', function (mixed $day, string $message) {
        $response = $this->postJson('/api/v1/adv/days', ['day' => $day]);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonPath('errors.day.0', $message);
        expect(BusinessDay::query()->count())->toBe(0);
    })->with([
        'missing' => [null, 'Le champ journée est obligatoire.'],
        'malformed' => ['08/10/2026', 'Date attendue au format AAAA-MM-JJ.'],
        'impossible' => ['2026-02-30', 'Date attendue au format AAAA-MM-JJ.'],
        'not a string' => [[2026, 10, 8], 'Date attendue au format AAAA-MM-JJ.'],
        'past' => ['2026-10-07', 'Une journée passée ne peut pas être ouverte.'],
        'beyond today + 30' => ['2026-11-08', 'Une journée s\'ouvre au plus 30 jours à l\'avance (jusqu\'au 07/11/2026).'],
    ]);

    it('refuses to open the same day twice (409 INVALID_TRANSITION)', function () {
        $this->postJson('/api/v1/adv/days', ['day' => '2026-10-11'])->assertCreated();

        $response = $this->postJson('/api/v1/adv/days', ['day' => '2026-10-11']);

        expectProblem($response, 409, 'INVALID_TRANSITION');
        $response->assertJsonPath('detail', 'La journée du 11/10/2026 est déjà ouverte.');
        expect(BusinessDay::query()->count())->toBe(1)
            ->and(AuditLog::query()->where('event', 'opened')->count())->toBe(1);
    });
});

describe('PATCH /adv/days/{day}', function () {
    beforeEach(function () {
        $this->day = $this->calendar->open(BusinessClock::parseDay('2026-10-08'), $this->adv);
        $this->patch = fn (array $body) => $this->patchJson('/api/v1/adv/days/2026-10-08', $body);
        // Clés d'erreur à point (« cutoffs.closing ») : lues sans chemin imbriqué.
        $this->error = fn ($response, string $field) => $response->json('errors')[$field][0] ?? null;
    });

    it('changes only the cutoffs provided and returns the day', function () {
        ($this->patch)(['cutoffs' => ['distribution' => '10:30', 'ordering' => '13:30']])
            ->assertOk()
            ->assertJsonPath('day', '2026-10-08')
            ->assertJsonPath('cutoffs', ['distribution' => '10:30', 'ordering' => '13:30', 'validation' => '15:00', 'closing' => '23:00'])
            ->assertJsonPath('cutoffs_at.distribution', '2026-10-08T09:30:00+00:00')
            ->assertJsonPath('regions.*.cutoffs.distribution', ['10:30', '10:30'])
            ->assertJsonPath('delivery_date', '2026-10-11');

        $entry = AuditLog::query()->where('event', 'schedule.updated')->sole();
        expect($entry->log_name)->toBe('days')
            ->and($entry->causer_id)->toBe($this->adv->id)
            ->and($entry->properties['old'])->toEqual(['distribution_cutoff_at' => '10:00', 'ordering_cutoff_at' => '13:00'])
            ->and($entry->properties['attributes'])->toEqual(['distribution_cutoff_at' => '10:30', 'ordering_cutoff_at' => '13:30']);
    });

    it('changes the delivery date, the day itself included', function () {
        ($this->patch)(['delivery_date' => '2026-10-12'])->assertOk()->assertJsonPath('delivery_date', '2026-10-12');
        ($this->patch)(['delivery_date' => '2026-10-08'])->assertOk()->assertJsonPath('delivery_date', '2026-10-08');

        expect(AuditLog::query()->where('event', 'schedule.updated')->latest('id')->first()->properties['attributes'])
            ->toEqual(['delivery_date' => '2026-10-08']);
    });

    it('changes every cutoff at once, the closing included', function () {
        ($this->patch)(['cutoffs' => ['distribution' => '09:00', 'ordering' => '12:00', 'validation' => '14:00', 'closing' => '22:00']])
            ->assertOk()
            ->assertJsonPath('cutoffs', ['distribution' => '09:00', 'ordering' => '12:00', 'validation' => '14:00', 'closing' => '22:00'])
            ->assertJsonPath('cutoffs_at.closing', '2026-10-08T21:00:00+00:00');
    });

    it('accepts an empty body and journals nothing', function () {
        ($this->patch)([])->assertOk()->assertJsonPath('cutoffs.distribution', '10:00');
        ($this->patch)(['cutoffs' => ['ordering' => '13:00']])->assertOk();

        expect(AuditLog::query()->where('event', 'schedule.updated')->exists())->toBeFalse();
    });

    it('validates the format of the input', function (array $body, string $field, string $message) {
        $response = ($this->patch)($body);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        expect(($this->error)($response, $field))->toBe($message);
    })->with([
        'hour out of range' => [['cutoffs' => ['distribution' => '25:00']], 'cutoffs.distribution', 'Heure attendue au format HH:MM (ex. 10:30).'],
        'hour without leading zero' => [['cutoffs' => ['ordering' => '9:30']], 'cutoffs.ordering', 'Heure attendue au format HH:MM (ex. 10:30).'],
        'seconds' => [['cutoffs' => ['validation' => '15:00:00']], 'cutoffs.validation', 'Heure attendue au format HH:MM (ex. 10:30).'],
        'empty hour' => [['cutoffs' => ['closing' => '']], 'cutoffs.closing', 'Le champ heure de clôture est obligatoire.'],
        'null hour' => [['cutoffs' => ['closing' => null]], 'cutoffs.closing', 'Le champ heure de clôture est obligatoire.'],
        'unknown cutoff' => [['cutoffs' => ['picking' => '16:00']], 'cutoffs', 'Heures limites attendues : distribution, ordering, validation ou closing, au format HH:MM.'],
        'cutoffs not an object' => [['cutoffs' => '10:00'], 'cutoffs', 'Heures limites attendues : distribution, ordering, validation ou closing, au format HH:MM.'],
        'malformed delivery date' => [['delivery_date' => '11/10/2026'], 'delivery_date', 'Date attendue au format AAAA-MM-JJ.'],
        'delivery date before the day' => [['delivery_date' => '2026-10-07'], 'delivery_date', 'La date de livraison ne peut pas précéder la journée (08/10/2026).'],
        'null delivery date' => [['delivery_date' => null], 'delivery_date', 'Le champ date de livraison est obligatoire.'],
    ]);

    it('keeps the cutoffs in strict order (distribution < ordering < validation < closing)', function (array $cutoffs, string $field) {
        $response = ($this->patch)(['cutoffs' => $cutoffs]);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        expect(($this->error)($response, $field))->toBe('Les heures limites doivent se suivre : répartition, commande, validation, puis clôture.')
            ->and($this->day->fresh()->cutoff(CutoffKind::Distribution)->equalTo($this->day->distribution_cutoff_at))->toBeTrue();
    })->with([
        'ordering before distribution' => [['ordering' => '09:00'], 'cutoffs.ordering'],
        'ordering equal to distribution' => [['distribution' => '13:00'], 'cutoffs.ordering'],
        'validation before ordering' => [['validation' => '12:00'], 'cutoffs.validation'],
        'closing before validation' => [['closing' => '14:00'], 'cutoffs.closing'],
    ]);

    it('requires a closing still to come', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 16:00', 'Africa/Algiers'));

        $response = ($this->patch)(['cutoffs' => ['closing' => '15:30']]);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        expect(($this->error)($response, 'cutoffs.closing'))->toBe('L\'heure de clôture doit être à venir.');
    });

    it('refuses a closing earlier than a region extension', function () {
        // Validation prolongée d'abord : la commande de la région reste avant elle.
        $this->calendar->extendCutoff($this->calendar->regionDayFor($this->day, $this->centre), CutoffKind::Validation, '21:00', $this->adv);
        $this->calendar->extendCutoff($this->calendar->regionDayFor($this->day, $this->centre), CutoffKind::Ordering, '20:00', $this->adv);

        $response = ($this->patch)(['cutoffs' => ['closing' => '19:00']]);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        expect(($this->error)($response, 'cutoffs.closing'))->toBe('Une prolongation accordée à une région dépasse cette heure de clôture.');
    });

    it('refuses to change a closed day (409 DAY_NOT_OPEN)', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 23:00', 'Africa/Algiers'));
        $this->artisan('ramymaak:close-days')->assertSuccessful();

        $response = ($this->patch)(['delivery_date' => '2026-10-12']);

        expectProblem($response, 409, 'DAY_NOT_OPEN');
        $response->assertJsonPath('detail', 'La journée du 08/10/2026 est clôturée : elle n\'est plus modifiable.');
        expect($this->day->fresh()->delivery_date->format('Y-m-d'))->toBe('2026-10-11');
    });
});
