<?php

namespace App\Notifications;

/**
 * N-05 (§7.8, I-57) : commande envoyée par un client de la région.
 * « Nouvelle commande — C0012 Superette El Baraka » /
 * « RMK-261008-00042 à valider. » Une notification par commande dans
 * l'application ; le regroupement des push arrive au lot 7.
 */
final class OrderSubmittedNotification extends OrderNotification
{
    public function type(): NotificationType
    {
        return NotificationType::OrderSubmitted;
    }

    public function title(): string
    {
        return __('notifications.order_submitted.title', ['code' => $this->clientCode, 'name' => $this->clientName]);
    }

    public function body(): string
    {
        return __('notifications.order_submitted.body', ['number' => $this->number]);
    }

    /** Une seule par commande et destinataire : une modification se notifie par N-06. */
    protected function uniqueKey(): string
    {
        return $this->keyPrefix();
    }
}
