<?php

namespace App\Events\Realtime;

/**
 * Commande annulée par l'ADV (RG-V07) : la file, le compteur et le suivi de la
 * journée (V restitué) se relisent, y compris les écrans d'un autre ADV.
 */
final class AdvOrderCancelledByAdv extends AdvOrderActivity
{
    public function broadcastAs(): string
    {
        return 'OrderCancelledByAdv';
    }
}
