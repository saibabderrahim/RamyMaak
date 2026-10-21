<?php

namespace App\Events\Realtime;

/**
 * Commande modifiée par le client (RG-C05), statut SUBMITTED, version
 * suivante : les commandes reçues et la grille de répartition (S) de la
 * région se relisent, comme les écrans du client.
 */
final class OrderUpdated extends OrderActivity
{
    public function broadcastAs(): string
    {
        return 'OrderUpdated';
    }
}
