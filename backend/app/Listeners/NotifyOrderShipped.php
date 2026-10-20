<?php

namespace App\Listeners;

use App\Domain\Orders\OrderStatus;
use App\Events\OrdersProcessed;
use App\Models\Order;
use App\Models\User;
use App\Notifications\ClientOrderNotification;
use App\Notifications\OrderShippedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * N-09 (§7.8, R13) : commandes passées à « Expédiée » par l'ADV (changement
 * unitaire ou en masse), notifiées aux comptes actifs de rôle client de
 * chaque client concerné, client bloqué compris. Les autres statuts de
 * traitement (En préparation, Préparée) ne notifient personne et ne mettent
 * même pas de tâche en file. Tâche de fond (EF-NOT-06), commandes et
 * destinataires lus par paquets, rejouable sans double envoi (§9.7) : une
 * notification par commande et destinataire.
 */
final class NotifyOrderShipped implements ShouldQueue
{
    /** Commandes lues par paquets de cette taille, avec les comptes de leurs clients. */
    private const CHUNK = 100;

    /** Relancée après un échec technique : les notifications déjà parties ne repartent pas. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /** Seule l'expédition notifie le client : pas de tâche pour un autre statut. */
    public function shouldQueue(OrdersProcessed $event): bool
    {
        return $event->to === OrderStatus::Shipped;
    }

    public function handle(OrdersProcessed $event): void
    {
        if ($event->to !== OrderStatus::Shipped) {
            return;
        }

        foreach (array_chunk(array_column($event->orders, 'orderId'), self::CHUNK) as $ids) {
            $orders = Order::query()
                ->whereKey($ids)
                // Expédiée, ou déjà livrée : un identifiant d'une commande qui n'a pas été expédiée est ignoré.
                ->whereIn('status', OrderStatus::values([OrderStatus::Shipped, OrderStatus::Delivered]))
                ->orderBy('id')
                ->get(['id', 'public_id', 'number', 'client_id']);
            if ($orders->isEmpty()) {
                continue;
            }

            $recipients = ClientOrderNotification::recipientsQuery($orders->pluck('client_id')->unique()->values()->all())
                ->orderBy('id')
                ->get()
                ->groupBy('client_id');
            foreach ($orders as $order) {
                /** @var iterable<User> $accounts */
                $accounts = $recipients->get($order->client_id, []);
                (new OrderShippedNotification($order))->sendOnce($accounts);
            }
        }
    }
}
