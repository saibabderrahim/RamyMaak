<?php

namespace App\Events\Realtime;

/**
 * Commande annulée par le client avant décision (§6.3), statut CANCELLED :
 * ses quantités en attente sont libérées (S), les écrans de la région et du
 * client se relisent.
 */
final class OrderCancelled extends OrderActivity
{
    public function broadcastAs(): string
    {
        return 'OrderCancelled';
    }
}
