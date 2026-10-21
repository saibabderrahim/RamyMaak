<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Commande annulée par le client avant décision (§6.3) : N-06 aux régionaux, temps réel.
 */
final class OrderCancelled implements ShouldDispatchAfterCommit
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
