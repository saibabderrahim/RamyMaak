<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Quotas d'une région publiés (RG-Q04) : première publication (N-01) ou
 * modifications après publication (N-02). Diffusé après la validation de la
 * transaction (§5.8, étape 4).
 */
final class QuotasPublished implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $regionDayId,
        public readonly int $version,
        public readonly bool $first,
        public readonly int $changedProducts,
        public readonly ?int $actorId,
    ) {}
}
