<?php

namespace App\Listeners;

use App\Domain\Orders\OrderStatus;
use App\Events\OrderCancelledByAdv;
use App\Events\OrderRejected;
use App\Events\OrderValidated;
use App\Models\Order;
use App\Notifications\OrderDecidedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * N-07 (§7.8, I-39) : décision sur la commande (validée, validée avec lignes
 * ajustées, rejetée, annulée par l'ADV), notifiée aux comptes actifs de rôle
 * client du client, client bloqué compris (I-53). L'annulation par l'ADV ne
 * produit que N-07 : N-06 (aux régionaux) ne concerne que l'annulation par le
 * client. Tâche de fond (EF-NOT-06), rejouable sans double envoi (§9.7) : une
 * notification par commande, version et destinataire.
 *
 * Statut, version et lignes ajustées viennent de l'événement, jamais de la
 * commande relue (OrderDecidedNotification::fromOrder) : la tâche peut
 * s'exécuter après la préparation de la commande, qui n'a alors plus de
 * décision à lire.
 */
final class NotifyOrderDecided implements ShouldQueue
{
    /** Relancée après un échec technique : les notifications déjà parties ne repartent pas. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function handle(OrderValidated|OrderRejected|OrderCancelledByAdv $event): void
    {
        $order = Order::query()->find($event->orderId, ['id', 'public_id', 'number', 'client_id']);
        if ($order === null) {
            return;
        }

        $notification = new OrderDecidedNotification(
            $order,
            match (true) {
                $event instanceof OrderValidated => OrderStatus::Validated,
                $event instanceof OrderRejected => OrderStatus::Rejected,
                default => OrderStatus::Cancelled,
            },
            $event->version,
            $event instanceof OrderValidated ? $event->adjustedLines : 0,
        );
        $notification->sendOnce($notification->recipients());
    }
}
