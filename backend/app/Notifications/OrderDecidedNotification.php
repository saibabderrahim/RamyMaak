<?php

namespace App\Notifications;

use App\Domain\Orders\OrderStatus;
use App\Models\Order;
use App\Models\OrderLine;
use InvalidArgumentException;

/**
 * N-07 (§7.8, I-39) : décision sur la commande, envoyée aux comptes du
 * client. Classe, textes et destinataires livrés au lot 5 ; les décisions
 * (validation, ajustement, rejet, annulation exceptionnelle) arrivent au
 * lot 6. Titre « Commande RMK-261008-00042 validée » (rejetée, annulée) ;
 * texte « Votre commande RMK-261008-00042 est validée. », « … est validée
 * (2 lignes ajustées). », « … est rejetée. » ou « … est annulée. »
 * Une notification par version : une annulation après la validation se
 * notifie à son tour.
 */
final class OrderDecidedNotification extends ClientOrderNotification
{
    /** Statuts atteints par une décision notifiée au client (§6.3, RG-V07). */
    public const DECISIONS = [OrderStatus::Validated, OrderStatus::Rejected, OrderStatus::Cancelled];

    /**
     * @param  OrderStatus  $decision  statut atteint : VALIDATED, REJECTED ou CANCELLED
     * @param  int  $version  version de la commande après la décision
     * @param  int  $adjustedLines  lignes validées à une autre quantité que la demande (validation)
     *
     * @throws InvalidArgumentException statut qui n'est pas une décision
     */
    public function __construct(
        Order $order,
        public readonly OrderStatus $decision,
        public readonly int $version,
        public readonly int $adjustedLines = 0,
    ) {
        if (! in_array($decision, self::DECISIONS, true)) {
            throw new InvalidArgumentException("Statut sans décision à notifier : {$decision->value}.");
        }
        parent::__construct($order);
    }

    /**
     * Décision lue sur la commande enregistrée : statut, version et, pour
     * une validation, lignes ajustées (quantité validée différente de la
     * quantité demandée). Lignes déjà chargées utilisées si présentes.
     *
     * @throws InvalidArgumentException statut qui n'est pas une décision
     */
    public static function fromOrder(Order $order): self
    {
        $adjusted = 0;
        if ($order->status === OrderStatus::Validated) {
            $lines = $order->relationLoaded('lines') ? $order->lines : $order->lines()->get(['id', 'requested_qty', 'validated_qty']);
            $adjusted = $lines->filter(fn (OrderLine $line) => $line->validated_qty !== null && $line->validated_qty !== $line->requested_qty)->count();
        }

        return new self($order, $order->status, $order->version, $adjusted);
    }

    public function type(): NotificationType
    {
        return NotificationType::OrderDecided;
    }

    public function title(): string
    {
        return __('notifications.order_decided.title.'.$this->decision->value, ['number' => $this->number]);
    }

    public function body(): string
    {
        if ($this->decision === OrderStatus::Validated && $this->adjustedLines > 0) {
            return trans_choice('notifications.order_decided.body.ADJUSTED', $this->adjustedLines, [
                'number' => $this->number,
                'count' => $this->adjustedLines,
            ]);
        }

        return __('notifications.order_decided.body.'.$this->decision->value, ['number' => $this->number]);
    }

    protected function uniqueKey(): string
    {
        return $this->keyPrefix().'|version:'.$this->version;
    }
}
