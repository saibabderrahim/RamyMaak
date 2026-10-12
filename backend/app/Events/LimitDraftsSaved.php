<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Bornes enregistrées en brouillon (saisie, Reprendre J-1) : les autres
 * écrans ouverts sur la répartition de la région se rafraîchissent.
 */
final class LimitDraftsSaved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $regionDayId,
        public readonly ?int $actorId,
    ) {}
}
