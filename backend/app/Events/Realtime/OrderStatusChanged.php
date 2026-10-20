<?php

namespace App\Events\Realtime;

/**
 * Statut de la commande changé sans action du client, diffusé sur son canal
 * `client.{clientId}` : expiration à la clôture (§4.4, lot 5) ; validation,
 * rejet, annulation par l'ADV, préparation et expédition (lot 6). Le client
 * relit sa commande par l'API.
 */
final class OrderStatusChanged extends OrderActivity
{
    public function broadcastAs(): string
    {
        return 'OrderStatusChanged';
    }
}
