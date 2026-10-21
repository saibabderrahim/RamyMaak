<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Annulation exceptionnelle par l'ADV d'une commande validée ou en
 * préparation (RG-V07, T08), quantités validées restituées : N-07
 * « annulée » au client, temps réel. Distinct d'OrderCancelled (annulation
 * par le client, N-06 aux régionaux).
 */
final class OrderCancelledByAdv implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $orderId,
        public readonly int $clientId,
        public readonly int $regionId,
        public readonly int $businessDayId,
        public readonly int $version,
        public readonly ?int $actorId,
    ) {}
}
