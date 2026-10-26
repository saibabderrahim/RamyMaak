<?php

namespace App\Domain\Orders;

use App\Events\OrdersExpired;
use App\Models\BusinessDay;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Support\Facades\DB;

/**
 * Clôture de la journée (§4.4, étape 1) : les commandes encore envoyées
 * passent « Expirée » (acteur système), ce qui libère leurs quantités en
 * attente ; N-14 aux clients. Appelée dans la transaction de
 * DayCalendar::close, après le verrou de la journée : un envoi en cours
 * se termine avant, ou voit la journée clôturée.
 */
final class OrderExpiry
{
    /** Numéros gardés dans le journal d'une clôture. */
    private const AUDIT_NUMBERS = 200;

    /**
     * @return list<int> commandes expirées
     */
    public function expire(BusinessDay $day): array
    {
        // Lecture verrouillante (lecture courante) : un écrivain qui ne prendrait
        // pas la journée en partagé est attendu, et seules les commandes encore
        // envoyées après lui reçoivent historique, journal et N-14.
        // Index imposé : la lecture ne pose ses verrous que sur cette journée,
        // jamais sur les commandes envoyées des autres journées ouvertes.
        $orders = Order::query()
            ->forceIndex('orders_day_status_index')
            ->where('business_day_id', $day->id)
            ->where('status', OrderStatus::Submitted->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'number', 'version']);
        if ($orders->isEmpty()) {
            return [];
        }

        $now = now();
        Order::query()
            ->whereKey($orders->modelKeys())
            ->where('status', OrderStatus::Submitted->value)
            ->update(['status' => OrderStatus::Expired->value, 'version' => DB::raw('version + 1'), 'updated_at' => $now]);
        OrderStatusHistory::query()->insert($orders->map(fn (Order $order) => [
            'order_id' => $order->id,
            'from_status' => OrderStatus::Submitted->value,
            'to_status' => OrderStatus::Expired->value,
            'version' => $order->version + 1,
            'user_id' => null,
            'actor' => OrderActor::System->value,
            'comment' => null,
            'created_at' => $now,
        ])->all());

        activity('orders')
            ->performedOn($day)
            ->event('expired')
            ->withProperties([
                'day' => $day->dayKey(),
                'count' => $orders->count(),
                'numbers' => $orders->take(self::AUDIT_NUMBERS)->pluck('number')->all(),
            ])
            ->log(__('orders.audit.expired'));

        $ids = $orders->modelKeys();
        event(new OrdersExpired($day->id, $ids));

        return $ids;
    }
}
