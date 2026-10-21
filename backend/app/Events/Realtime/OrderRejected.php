<?php

namespace App\Events\Realtime;

/**
 * Commande rejetée par le régional (RG-V05), statut REJECTED : les commandes
 * reçues et « Ma journée » de la région se relisent. Diffusée avec le client
 * sur `region-day.{regionId}.{day}` ; le client reçoit OrderStatusChanged.
 * Rien pour l'ADV : une commande rejetée n'entre pas dans sa file.
 */
final class OrderRejected extends OrderActivity
{
    public function broadcastAs(): string
    {
        return 'OrderRejected';
    }
}
