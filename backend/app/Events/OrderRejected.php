<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Commande rejetée par le régional, motif obligatoire (RG-V05) : N-07 au
 * client, temps réel de la région et du client. Identifiants seulement,
 * diffusé après le commit.
 */
final class OrderRejected implements ShouldDispatchAfterCommit
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
