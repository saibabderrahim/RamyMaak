<?php

namespace App\Notifications;

use App\Models\Order;

/**
 * Notification portant sur une commande, adressée aux régionaux de sa
 * région ce jour-là (§7.8 : N-05, N-06 ; RegionStaff, titulaire et
 * suppléants actifs). Un appui ouvre la commande reçue (EF-NOT-03). Le
 * client est nommé, sans montant ni quantité (EF-NOT-03).
 */
abstract class OrderNotification extends AppNotification
{
    // Pas de « readonly » ici : SerializesModels (file de diffusion) ne sait
    // pas restaurer une propriété en lecture seule déclarée par une classe
    // parente.
    public int $orderId;

    /** Identifiant exposé (`public_id`, ULID). */
    public string $orderPublicId;

    /** Numéro lisible « RMK-AAMMJJ-NNNNN ». */
    public string $number;

    public string $clientCode;

    public string $clientName;

    /** Commande chargée avec son client. */
    public function __construct(Order $order)
    {
        $this->orderId = $order->id;
        $this->orderPublicId = $order->public_id;
        $this->number = $order->number;
        $this->clientCode = $order->client->code;
        $this->clientName = $order->client->name;
    }

    public function url(): ?string
    {
        return '/regional/commandes/'.$this->orderPublicId;
    }

    protected function keyPrefix(): string
    {
        return $this->type()->value.'|order:'.$this->orderId;
    }
}
