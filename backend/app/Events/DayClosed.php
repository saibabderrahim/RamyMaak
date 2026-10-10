<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Journée clôturée (§4.4). */
final class DayClosed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $businessDayId) {}
}
