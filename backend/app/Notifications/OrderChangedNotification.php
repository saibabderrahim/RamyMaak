<?php

namespace App\Notifications;

use App\Models\Order;

/**
 * N-06 (§7.8, RG-C05) : commande modifiée ou annulée par le client avant
 * décision. « Commande RMK-261008-00042 modifiée » / « Superette El Baraka
 * a modifié sa commande. », ou « … annulée » / « … a annulé sa commande. »
 * Une notification par version : deux modifications se notifient chacune.
 */
final class OrderChangedNotification extends OrderNotification
{
    /**
     * @param  Order  $order  commande chargée avec son client
     * @param  int  $version  version de la commande après la modification ou l'annulation (événement)
     */
    public function __construct(Order $order, public readonly int $version, public readonly bool $cancelled)
    {
        parent::__construct($order);
    }

    public function type(): NotificationType
    {
        return NotificationType::OrderChanged;
    }

    public function title(): string
    {
        return __('notifications.order_changed.title.'.$this->variant(), ['number' => $this->number]);
    }

    public function body(): string
    {
        return __('notifications.order_changed.body.'.$this->variant(), ['name' => $this->clientName]);
    }

    protected function uniqueKey(): string
    {
        return $this->keyPrefix().'|version:'.$this->version;
    }

    private function variant(): string
    {
        return $this->cancelled ? 'cancelled' : 'updated';
    }
}
