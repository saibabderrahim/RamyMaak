<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Commande validée par le régional, avec ou sans ajustement (RG-V02 à
 * RG-V04, §5.8) : N-07 au client, N-08 (temps réel) à l'ADV, temps réel de
 * la région et du client. `adjustedLines` : lignes validées sous la quantité
 * demandée. Identifiants seulement, diffusé après le commit.
 */
final class OrderValidated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $orderId,
        public readonly int $clientId,
        public readonly int $regionId,
        public readonly int $businessDayId,
        public readonly int $version,
        public readonly ?int $actorId,
        public readonly int $adjustedLines,
    ) {}
}
