<?php

use App\Domain\Access\Role;
use App\Domain\Days\CutoffKind;
use App\Domain\Days\DayCalendar;
use App\Domain\Quotas\QuotaBook;
use App\Http\Resources\DayResource;
use App\Models\Product;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

// Ressource « Day » du contrat du lot 3 (docs/lot-3-contrat-api.md §3).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 07:30', 'Africa/Algiers'));
    $this->adv = userWithRole(Role::Adv);
    $this->centre = Region::factory()->create(['code' => 'CENTRE', 'name' => 'Centre']);
    $this->est = Region::factory()->create(['code' => 'EST']);
    $this->calendar = app(DayCalendar::class);
    $this->day = $this->calendar->open(BusinessClock::parseDay('2026-10-08'), $this->adv);
    $this->resolve = fn ($user) => json_decode(json_encode(
        DayResource::make($this->day->fresh())->resolve(Request::create('/')->setUserResolver(fn () => $user)),
    ), true);
});

it('exposes local cutoffs, UTC instants, drafts and extensions per region', function () {
    app(QuotaBook::class)->saveDrafts($this->day, [
        ['region_id' => $this->centre->id, 'product_id' => Product::factory()->create()->id, 'quantity' => 500],
    ], $this->adv);
    $this->calendar->extendCutoff($this->calendar->regionDayFor($this->day, $this->centre), CutoffKind::Distribution, '11:15', $this->adv);

    $data = ($this->resolve)($this->adv);

    expect($data)->toMatchArray([
        'day' => '2026-10-08',
        'weekday' => 4,
        'status' => 'OPEN',
        'delivery_date' => '2026-10-11',
        'cutoffs' => ['distribution' => '10:00', 'ordering' => '13:00', 'validation' => '15:00', 'closing' => '23:00'],
    ])
        ->and($data['cutoffs_at']['closing'])->toBe('2026-10-08T22:00:00+00:00')
        ->and($data['created_by']['id'])->toBe($this->adv->id);

    $regions = $data['regions'];
    expect(array_column(array_column($regions, 'region'), 'code'))->toBe(['CENTRE', 'EST'])
        ->and($regions[0])->toMatchArray([
            'status' => 'QUOTA_DRAFT',
            'quota_version' => 0,
            'pending_drafts' => 1,
            'cutoffs' => ['distribution' => '11:15', 'ordering' => '13:00', 'validation' => '15:00'],
            'extended' => ['distribution' => true, 'ordering' => false, 'validation' => false],
        ])
        ->and($regions[1]['pending_drafts'])->toBe(0);
});

it('lists only the active regions of the reader perimeter, including regions created since the opening', function () {
    $restricted = userWithRole(Role::Adv);
    RegionAssignment::factory()->adv()->create(['region_id' => $this->est->id, 'user_id' => $restricted->id, 'starts_on' => '2026-10-01']);
    $ouest = Region::factory()->create(['code' => 'OUEST']);
    $this->centre->update(['is_active' => false]);

    $codes = fn ($user) => array_column(array_column(($this->resolve)($user)['regions'], 'region'), 'code');
    expect($codes($restricted))->toBe(['EST'])
        ->and($codes($this->adv))->toBe(['EST', 'OUEST'])
        ->and($this->day->regionDays()->where('region_id', $ouest->id)->exists())->toBeTrue();
});
