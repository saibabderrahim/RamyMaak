<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Commande envoyée par un client (RG-C01 à RG-C07) : N-05 aux régionaux de la région, temps réel.
 */
final class OrderSubmitted implements ShouldDispatchAfterCommit
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
