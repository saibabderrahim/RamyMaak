<?php

namespace App\Events\Realtime;

/**
 * Commande envoyée par un client (RG-C01 à RG-C07), statut SUBMITTED : les
 * commandes reçues et la grille de répartition (S) de la région se relisent,
 * comme les écrans du client.
 */
final class OrderSubmitted extends OrderActivity
{
    public function broadcastAs(): string
    {
        return 'OrderSubmitted';
    }
}
