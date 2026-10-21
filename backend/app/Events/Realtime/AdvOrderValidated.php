<?php

namespace App\Events\Realtime;

/**
 * Commande validée (N-08) : la file « À préparer » de l'ADV et le compteur de
 * son menu « Commandes » se relisent, comme le suivi de la journée (V).
 */
final class AdvOrderValidated extends AdvOrderActivity
{
    public function broadcastAs(): string
    {
        return 'OrderValidated';
    }
}
