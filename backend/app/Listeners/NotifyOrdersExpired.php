<?php

namespace App\Listeners;

use App\Domain\Orders\OrderStatus;
use App\Events\OrdersExpired;
use App\Models\Order;
use App\Models\User;
use App\Notifications\ClientOrderNotification;
use App\Notifications\OrderExpiredNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * N-14 (§7.8, §4.4, I-41) : commandes expirées à la clôture, notifiées aux
 * comptes actifs de rôle client de chaque client concerné, client bloqué
 * compris (I-53). Tâche de fond (EF-NOT-06 : toutes les commandes restées
 * envoyées de la journée), commandes et destinataires lus par paquets,
 * rejouable sans double envoi (§9.7).
 */
final class NotifyOrdersExpired implements ShouldQueue
{
    /** Commandes lues par paquets de cette taille, avec les comptes de leurs clients. */
    private const CHUNK = 100;

    /** Relancée après un échec technique : les notifications déjà parties ne repartent pas. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function handle(OrdersExpired $event): void
    {
        foreach (array_chunk($event->orderIds, self::CHUNK) as $ids) {
            $orders = Order::query()
                ->whereKey($ids)
                // Statut terminal : un identifiant d'une autre commande est ignoré.
                ->where('status', OrderStatus::Expired->value)
                ->orderBy('id')
                ->get(['id', 'public_id', 'number', 'client_id', 'version']);
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
                (new OrderExpiredNotification($order))->sendOnce($accounts);
            }
        }
    }
}
