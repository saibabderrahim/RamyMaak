<?php

namespace App\Events;

use App\Domain\Orders\OrderStatus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Statut de traitement atteint par une ou plusieurs commandes (RG-A02 :
 * En préparation, Préparée ou Expédiée), en un même appel de l'ADV
 * (`batch`, ULID commun au journal) : N-09 au client à l'expédition, temps
 * réel. Seules les commandes effectivement passées au statut y figurent.
 */
final class OrdersProcessed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<array{orderId: int, clientId: int, regionId: int, businessDayId: int, version: int}>  $orders
     */
    public function __construct(
        public readonly array $orders,
        public readonly OrderStatus $to,
        public readonly ?int $actorId,
        public readonly string $batch,
    ) {}
}
