<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Bornes enregistrées en brouillon (saisie, Reprendre J-1) : les autres
 * écrans ouverts sur la répartition de la région se rafraîchissent.
 * `statusChanged` : premier brouillon, la région passe en « Répartition en
 * cours » (§6.1) ; les écrans de l'ADV le reflètent.
 */
final class LimitDraftsSaved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $regionDayId,
        public readonly ?int $actorId,
        public readonly bool $statusChanged = false,
    ) {}
}
