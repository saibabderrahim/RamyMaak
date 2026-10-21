<?php

namespace App\Domain\Orders;

use App\Models\Order;
use App\Models\OrderStatusHistory;

/**
 * Historique des commandes (§6.3) : une ligne par transition, avec l'acteur
 * du moment (I-65 : CLIENT, REGIONAL, ADV ou SYSTEM, jamais le rôle actuel
 * du compte), l'auteur, la date et le commentaire. Version enregistrée :
 * celle de la commande après la transition (`$order->version`).
 */
final class OrderHistory
{
    public function record(Order $order, ?OrderStatus $from, OrderStatus $to, OrderActor $actor, ?int $userId, ?string $comment = null): void
    {
        OrderStatusHistory::create([
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'version' => $order->version,
            'user_id' => $userId,
            'actor' => $actor,
            'comment' => $comment,
            'created_at' => now(),
        ]);
    }

    /**
     * Même transition pour plusieurs commandes (traitement ADV en masse), en
     * une instruction.
     *
     * @param  iterable<Order>  $orders  versions déjà incrémentées
     */
    public function recordMany(iterable $orders, OrderStatus $from, OrderStatus $to, OrderActor $actor, ?int $userId, ?string $comment = null): void
    {
        $now = now();
        $rows = [];
        foreach ($orders as $order) {
            $rows[] = [
                'order_id' => $order->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'version' => $order->version,
                'user_id' => $userId,
                'actor' => $actor->value,
                'comment' => $comment,
                'created_at' => $now,
            ];
        }
        if ($rows !== []) {
            OrderStatusHistory::query()->insert($rows);
        }
    }
}
