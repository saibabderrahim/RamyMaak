<?php

namespace App\Http\Resources\Orders;

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Orders\AdvOrders;
use App\Domain\Orders\OrderActor;
use App\Domain\Orders\OrderStatus;
use App\Domain\Orders\OrderWorkflow;
use App\Models\Order;
use App\Models\User;

/**
 * Droits d'action de l'appelant sur les commandes de la file ADV (contrat du
 * lot 6, §5) : étapes de traitement atteignables (`order.process`),
 * annulation exceptionnelle (`order.cancel-validated`), saisie de
 * l'expédition et impression. Réservés à l'ADV : l'Admin lit sans agir
 * (I-87), tout est vide ou faux pour lui. Le serveur reste juge : AdvOrders
 * rejoue tous les contrôles.
 */
final class AdvOrderActions
{
    public function __construct(
        private readonly OrderWorkflow $workflow,
        private readonly bool $canProcess,
        private readonly bool $canCancel,
    ) {}

    public static function for(User $user): self
    {
        $adv = $user->hasAppRole(Role::Adv);

        return new self(
            app(OrderWorkflow::class),
            $adv && $user->can(Permission::OrderProcess->value),
            $adv && $user->can(Permission::OrderCancelValidated->value),
        );
    }

    /**
     * Statuts de traitement que l'appelant peut appliquer à la commande.
     *
     * @return list<string>
     */
    public function nextStatuses(Order $order): array
    {
        if (! $this->canProcess) {
            return [];
        }

        return array_values(array_map(
            fn (OrderStatus $status) => $status->value,
            array_filter(
                $this->workflow->targets($order->status, OrderActor::Adv),
                fn (OrderStatus $status) => in_array($status, AdvOrders::processingStatuses(), true),
            ),
        ));
    }

    /** Annulation exceptionnelle : Validée ou En préparation, avec le droit d'annuler. */
    public function canCancel(Order $order): bool
    {
        return $this->canCancel && $this->workflow->can($order->status, OrderStatus::Cancelled, OrderActor::Adv);
    }

    /**
     * Droits du détail (`can`) : quantités expédiées En préparation et
     * Préparée, bon de livraison jusqu'à Expédiée (I-77), impression pour
     * toute commande de la file (I-86).
     *
     * @return array{next_statuses: list<string>, edit_shipping_lines: bool, edit_delivery_note: bool, cancel: bool, print: bool}
     */
    public function detail(Order $order): array
    {
        $status = $order->status;

        return [
            'next_statuses' => $this->nextStatuses($order),
            'edit_shipping_lines' => $this->canProcess && in_array($status, [OrderStatus::InPreparation, OrderStatus::Prepared], true),
            'edit_delivery_note' => $this->canProcess && in_array($status, [OrderStatus::InPreparation, OrderStatus::Prepared, OrderStatus::Shipped], true),
            'cancel' => $this->canCancel($order),
            'print' => $this->canProcess,
        ];
    }
}
