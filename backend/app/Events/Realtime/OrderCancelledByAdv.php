<?php

namespace App\Events\Realtime;

/**
 * Commande validée annulée par l'ADV (RG-V07, T08), statut CANCELLED :
 * V(r,p) est restitué, les écrans de la région se relisent. Diffusée avec le
 * client sur `region-day.{regionId}.{day}` ; la file de l'ADV reçoit
 * AdvOrderCancelledByAdv, le client OrderStatusChanged. Distincte de
 * OrderCancelled, l'annulation par le client avant décision.
 */
final class OrderCancelledByAdv extends OrderActivity
{
    public function broadcastAs(): string
    {
        return 'OrderCancelledByAdv';
    }
}
