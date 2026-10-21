<?php

namespace App\Events;

use App\Domain\Days\CutoffKind;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Heure limite prolongée pour une région (RG-A05). */
final class CutoffExtended implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $regionDayId,
        public readonly CutoffKind $kind,
        public readonly ?int $actorId,
    ) {}
}
