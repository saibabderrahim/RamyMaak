<?php

use App\Support\BusinessClock;
use Illuminate\Support\Carbon;

it('computes business time in Africa/Algiers while storage stays in UTC (H-10, §9.4)', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 23:30:00', 'UTC'));

    expect(config('app.timezone'))->toBe('UTC')
        ->and(BusinessClock::now()->format('Y-m-d H:i'))->toBe('2026-10-08 00:30')
        ->and(BusinessClock::today()->format('Y-m-d'))->toBe('2026-10-08');
});

it('parses business days strictly', function () {
    expect(BusinessClock::parseDay('2026-10-08')?->utc()->toIso8601String())->toBe('2026-10-07T23:00:00+00:00')
        ->and(BusinessClock::parseDay('2026-02-30'))->toBeNull()
        ->and(BusinessClock::parseDay('08/10/2026'))->toBeNull()
        ->and(BusinessClock::parseDay('2026-10-8'))->toBeNull();
});
