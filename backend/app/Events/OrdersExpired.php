<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Commandes encore envoyées passées « Expirée » à la clôture de la journée
 * (§4.4) : N-14 aux clients, temps réel.
 */
final class OrdersExpired implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>  $orderIds
     */
    public function __construct(
        public readonly int $businessDayId,
        public readonly array $orderIds,
    ) {}
}
