<?php

use App\Domain\Access\Role;
use App\Domain\Days\CutoffKind;
use App\Domain\Days\DayCalendar;
use App\Domain\Quotas\QuotaBook;
use App\Domain\Settings\Settings;
use App\Events\CutoffExtended;
use App\Events\DayClosed;
use App\Events\QuotaDraftsSaved;
use App\Models\BusinessDay;
use App\Models\Product;
use App\Models\Region;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// Calendrier des journées et ressource Day (EF-ADV-01 ; contrat du lot 3, §3).

beforeEach(function () {
    // Jeudi 8 octobre 2026, 07:30 à Alger (jour ouvré).
    $this->travelTo(CarbonImmutable::parse('2026-10-08 07:30', 'Africa/Algiers'));
    Event::fake([CutoffExtended::class, DayClosed::class, QuotaDraftsSaved::class]);
    $this->calendar = app(DayCalendar::class);
    $this->adv = userWithRole(Role::Adv, ['username' => 'a.adv', 'name' => 'ADV Centre']);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->est = Region::factory()->create(['code' => 'EST', 'name' => 'Est']);
    $this->open = fn (string $day) => $this->calendar->open(BusinessClock::parseDay($day), $this->adv);
    $this->actingAs($this->adv);
});

describe('the Day resource', function () {
    it('exposes a day exactly as the contract describes it', function () {
        $day = ($this->open)('2026-10-08');
        app(QuotaBook::class)->saveDrafts($day, [
            ['region_id' => $this->centre->id, 'product_id' => Product::factory()->create()->id, 'quantity' => 500],
            ['region_id' => $this->centre->id, 'product_id' => Product::factory()->create()->id, 'quantity' => 40],
        ], $this->adv);
        $this->calendar->extendCutoff($this->calendar->regionDayFor($day, $this->est), CutoffKind::Ordering, '14:00', $this->adv);

        $this->getJson('/api/v1/adv/days/2026-10-08')->assertOk()->assertExactJson([
            'day' => '2026-10-08',
            'weekday' => 4,
            'is_working_day' => true,
            'status' => 'OPEN',
            'status_label' => 'Ouverte',
            'delivery_date' => '2026-10-11',
            'cutoffs' => ['distribution' => '10:00', 'ordering' => '13:00', 'validation' => '15:00', 'closing' => '23:00'],
            'cutoffs_at' => [
                'distribution' => '2026-10-08T09:00:00+00:00',
                'ordering' => '2026-10-08T12:00:00+00:00',
                'validation' => '2026-10-08T14:00:00+00:00',
                'closing' => '2026-10-08T22:00:00+00:00',
            ],
            'closed_at' => null,
            'regions' => [
                [
                    'region' => ['id' => $this->centre->id, 'code' => 'CENTRE', 'name' => 'Centre'],
                    'status' => 'QUOTA_DRAFT',
                    'status_label' => 'Quotas en brouillon',
                    'quota_version' => 0,
                    'quota_published_at' => null,
                    'quota_last_published_at' => null,
                    'distribution_version' => 0,
                    'distribution_published_at' => null,
                    'pending_drafts' => 2,
                    'cutoffs' => ['distribution' => '10:00', 'ordering' => '13:00'],
                    'extended' => ['distribution' => false, 'ordering' => false],
                ],
                [
                    'region' => ['id' => $this->est->id, 'code' => 'EST', 'name' => 'Est'],
                    'status' => 'QUOTA_DRAFT',
                    'status_label' => 'Quotas en brouillon',
                    'quota_version' => 0,
                    'quota_published_at' => null,
                    'quota_last_published_at' => null,
                    'distribution_version' => 0,
                    'distribution_published_at' => null,
                    'pending_drafts' => 0,
                    'cutoffs' => ['distribution' => '10:00', 'ordering' => '14:00'],
                    'extended' => ['distribution' => false, 'ordering' => true],
                ],
            ],
            'created_by' => ['id' => $this->adv->id, 'username' => 'a.adv', 'name' => 'ADV Centre'],
            'created_at' => '2026-10-08T06:30:00+00:00',
        ]);
    });

    it('marks non-working days and closed days, and keeps a day opened by a deleted account', function () {
        $friday = ($this->open)('2026-10-09');
        DB::table('business_days')->where('id', $friday->id)->update(['created_by' => null]);
        $this->travelTo(CarbonImmutable::parse('2026-10-09 23:00', 'Africa/Algiers'));
        $this->calendar->closeDue();

        $this->getJson('/api/v1/adv/days/2026-10-09')
            ->assertOk()
            ->assertJsonPath('weekday', 5)
            ->assertJsonPath('is_working_day', false)
            ->assertJsonPath('status', 'CLOSED')
            ->assertJsonPath('status_label', 'Clôturée')
            ->assertJsonPath('closed_at', '2026-10-09T22:00:00+00:00')
            ->assertJsonPath('regions.*.status', ['CLOSED', 'CLOSED'])
            ->assertJsonPath('created_by', null);
    });

    it('lists regions created since the opening as quota drafts, sorted by code, without inactive ones', function () {
        ($this->open)('2026-10-08');
        Region::factory()->create(['code' => 'AAA', 'name' => 'Nouvelle']);
        $this->est->update(['is_active' => false]);

        $this->getJson('/api/v1/adv/days/2026-10-08')
            ->assertOk()
            ->assertJsonPath('regions.*.region.code', ['AAA', 'CENTRE'])
            ->assertJsonPath('regions.0.status', 'QUOTA_DRAFT');
    });
});

describe('GET /adv/days', function () {
    it('lists the opened days of the default window (today − 7 to today + 14), ordered by day', function () {
        foreach (['2026-10-22', '2026-10-01', '2026-10-08', '2026-10-23', '2026-09-30', '2026-10-12'] as $day) {
            BusinessDay::factory()->on($day)->create();
        }
        BusinessDay::factory()->on('2026-10-04')->closed()->create();

        $this->getJson('/api/v1/adv/days')
            ->assertOk()
            ->assertJsonPath('data.*.day', ['2026-10-01', '2026-10-04', '2026-10-08', '2026-10-12', '2026-10-22'])
            ->assertJsonPath('data.1.status', 'CLOSED')
            ->assertJsonPath('meta', ['from' => '2026-10-01', 'to' => '2026-10-22', 'today' => '2026-10-08', 'working_days' => [0, 1, 2, 3, 4]]);
    });

    it('returns full Day resources with the regions of each day', function () {
        ($this->open)('2026-10-08');
        ($this->open)('2026-10-11');

        $response = $this->getJson('/api/v1/adv/days')->assertOk()->assertJsonCount(2, 'data');

        expect(array_keys($response->json('data.0')))->toBe([
            'day', 'weekday', 'is_working_day', 'status', 'status_label', 'delivery_date', 'cutoffs', 'cutoffs_at',
            'closed_at', 'regions', 'created_by', 'created_at',
        ])
            ->and($response->json('data.1.regions.*.region.code'))->toBe(['CENTRE', 'EST'])
            ->and($response->json('data.1.created_by.username'))->toBe('a.adv');
    });

    it('accepts an explicit interval, bounds included', function () {
        foreach (['2026-11-01', '2026-11-15', '2026-11-30', '2026-12-01'] as $day) {
            BusinessDay::factory()->on($day)->create();
        }

        $this->getJson('/api/v1/adv/days?from=2026-11-01&to=2026-11-30')
            ->assertOk()
            ->assertJsonPath('data.*.day', ['2026-11-01', '2026-11-15', '2026-11-30'])
            ->assertJsonPath('meta.from', '2026-11-01')
            ->assertJsonPath('meta.to', '2026-11-30')
            ->assertJsonPath('meta.today', '2026-10-08');
    });

    it('keeps the default width when only one bound is given', function () {
        $this->getJson('/api/v1/adv/days?from=2026-11-01')->assertOk()
            ->assertJsonPath('meta.from', '2026-11-01')->assertJsonPath('meta.to', '2026-11-22');
        $this->getJson('/api/v1/adv/days?to=2026-09-30')->assertOk()
            ->assertJsonPath('meta.from', '2026-09-09')->assertJsonPath('meta.to', '2026-09-30');
    });

    it('accepts a span of 62 days and refuses more', function () {
        $this->getJson('/api/v1/adv/days?from=2026-10-01&to=2026-12-02')->assertOk();

        $response = $this->getJson('/api/v1/adv/days?from=2026-10-01&to=2026-12-03');
        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonPath('errors.to.0', 'L\'intervalle ne peut pas dépasser 62 jours.');
    });

    it('validates the bounds', function (string $query, string $field) {
        $response = $this->getJson('/api/v1/adv/days?'.$query);

        expectProblem($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonValidationErrors([$field]);
    })->with([
        'malformed from' => ['from=08/10/2026', 'from'],
        'impossible date' => ['from=2026-02-30&to=2026-03-10', 'from'],
        'malformed to' => ['to=2026-10', 'to'],
        'empty from' => ['from=', 'from'],
        'array from' => ['from[]=2026-10-01', 'from'],
        'to before from' => ['from=2026-10-10&to=2026-10-09', 'to'],
    ]);

    it('takes the working days from the settings', function () {
        app(Settings::class)->update(['calendar' => ['working_days' => [6, 0, 1, 2, 3]]], null);

        $this->getJson('/api/v1/adv/days')->assertOk()->assertJsonPath('meta.working_days', [0, 1, 2, 3, 6]);
    });

    it('stays bounded in queries: a constant batch per day, none per region', function () {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/adv/days')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        ($this->open)('2026-10-08');
        $count(); // Session, rôles et paramètres mis en cache.
        $one = $count();
        ($this->open)('2026-10-11');
        $two = $count();
        ($this->open)('2026-10-12');
        $three = $count();
        Region::factory()->count(5)->create();
        $this->calendar->syncRegions(BusinessDay::forDay('2026-10-08'));
        $this->calendar->syncRegions(BusinessDay::forDay('2026-10-11'));
        $this->calendar->syncRegions(BusinessDay::forDay('2026-10-12'));
        $moreRegions = $count();

        expect($three - $two)->toBe($two - $one)
            ->and($moreRegions)->toBe($three);
    });
});
