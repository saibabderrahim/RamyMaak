<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Brouillons de quotas enregistrés (saisie, copie ou import, RG-Q03) :
 * les autres écrans ADV ouverts sur la journée se rafraîchissent.
 */
final class QuotaDraftsSaved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>  $regionIds  régions dont au moins une cellule a changé
     */
    public function __construct(
        public readonly int $businessDayId,
        public readonly array $regionIds,
        public readonly ?int $actorId,
    ) {}
}
