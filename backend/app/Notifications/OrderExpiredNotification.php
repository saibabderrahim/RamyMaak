<?php

namespace App\Notifications;

/**
 * N-14 (§7.8, §4.4, I-41) : commande encore envoyée passée « Expirée » à la
 * clôture de la journée. « Commande RMK-261008-00042 expirée » / « Elle n'a
 * pas été validée avant la clôture de la journée. »
 */
final class OrderExpiredNotification extends ClientOrderNotification
{
    public function type(): NotificationType
    {
        return NotificationType::OrderExpired;
    }

    public function title(): string
    {
        return __('notifications.order_expired.title', ['number' => $this->number]);
    }

    public function body(): string
    {
        return __('notifications.order_expired.body');
    }

    /** Une seule par commande et destinataire : l'expiration est définitive. */
    protected function uniqueKey(): string
    {
        return $this->keyPrefix();
    }
}
