<?php

namespace App\Notifications;

/**
 * N-09 (§7.8, R13) : commande expédiée par l'ADV, notifiée aux comptes du
 * client. « Commande RMK-261008-00042 expédiée » / « Votre commande
 * RMK-261008-00042 a été expédiée. » Ni montant ni quantité. Une seule
 * notification par commande et destinataire : l'expédition est définitive.
 */
final class OrderShippedNotification extends ClientOrderNotification
{
    public function type(): NotificationType
    {
        return NotificationType::OrderShipped;
    }

    public function title(): string
    {
        return __('notifications.order_shipped.title', ['number' => $this->number]);
    }

    public function body(): string
    {
        return __('notifications.order_shipped.body', ['number' => $this->number]);
    }

    protected function uniqueKey(): string
    {
        return $this->keyPrefix();
    }
}
