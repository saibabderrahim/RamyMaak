<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Quantités expédiées, motifs d'écart ou bon de livraison saisis par l'ADV
 * (RG-A03, I-79) : temps réel de la file ADV, sans notification.
 */
final class OrderShippingUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $orderId,
        public readonly int $businessDayId,
        public readonly int $version,
        public readonly ?int $actorId,
    ) {}
}
