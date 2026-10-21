<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Répartition d'une région envoyée aux clients (RG-B06) : première
 * publication (N-03 aux clients notifiés) ou bornes modifiées (N-04 aux
 * seuls clients impactés, RG-B07).
 */
final class DistributionPublished implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>  $notifiedClientIds  clients à notifier
     */
    public function __construct(
        public readonly int $regionDayId,
        public readonly int $version,
        public readonly bool $first,
        public readonly array $notifiedClientIds,
        public readonly ?int $actorId,
    ) {}
}
