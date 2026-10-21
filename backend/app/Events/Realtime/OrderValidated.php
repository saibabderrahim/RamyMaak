<?php

namespace App\Events\Realtime;

/**
 * Commande validée par le régional (§5.8, lot 6), statut VALIDATED : les
 * commandes reçues, la grille de répartition, les quotas du jour et « Ma
 * journée » de la région se relisent (V(r,p) a changé). Diffusée avec le
 * client sur `region-day.{regionId}.{day}` ; la file de l'ADV reçoit
 * AdvOrderValidated (N-08), le client OrderStatusChanged.
 */
final class OrderValidated extends OrderActivity
{
    public function broadcastAs(): string
    {
        return 'OrderValidated';
    }
}
