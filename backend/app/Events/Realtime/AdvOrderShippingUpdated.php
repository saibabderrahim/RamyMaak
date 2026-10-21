<?php

namespace App\Events\Realtime;

/**
 * Quantités expédiées, motifs d'écart ou bon de livraison saisis (RG-A03,
 * I-79) : la file et le détail de la commande se relisent. Le statut est
 * celui lu à la diffusion (l'événement de domaine n'en porte pas) ; la
 * version, elle, est celle de la saisie.
 */
final class AdvOrderShippingUpdated extends AdvOrderActivity
{
    public function broadcastAs(): string
    {
        return 'OrderShippingUpdated';
    }
}
