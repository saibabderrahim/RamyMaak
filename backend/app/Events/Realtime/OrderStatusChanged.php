<?php

namespace App\Events\Realtime;

/**
 * Statut de la commande changé sans action du client, diffusé sur son canal
 * `client.{clientId}` : expiration à la clôture au lot 5 (§4.4), décisions au
 * lot 6.
 */
final class OrderStatusChanged extends OrderActivity
{
    public function broadcastAs(): string
    {
        return 'OrderStatusChanged';
    }
}
