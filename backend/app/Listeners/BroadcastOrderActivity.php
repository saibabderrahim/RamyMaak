<?php

namespace App\Listeners;

use App\Domain\Orders\OrderStatus;
use App\Events\OrderCancelled;
use App\Events\OrdersExpired;
use App\Events\OrderSubmitted;
use App\Events\OrderUpdated;
use App\Events\Realtime\OrderActivity;
use App\Events\Realtime\OrderCancelled as RealtimeOrderCancelled;
use App\Events\Realtime\OrdersExpired as RealtimeOrdersExpired;
use App\Events\Realtime\OrderStatusChanged;
use App\Events\Realtime\OrderSubmitted as RealtimeOrderSubmitted;
use App\Events\Realtime\OrderUpdated as RealtimeOrderUpdated;
use App\Models\BusinessDay;
use App\Models\Order;
use Closure;
use Throwable;

/**
 * Relais des commandes vers le temps réel (§9.6 ; contrat du lot 5, §5) :
 * chaque événement diffusé ne porte que des identifiants, sans quantité ;
 * sa diffusion part en tâche de fond (ShouldBroadcast). Exécuté pendant la
 * requête, après la validation de la transaction : un échec du relais est
 * journalisé sans faire échouer l'envoi, la modification, l'annulation ou
 * la clôture déjà enregistrés (repli : rafraîchissement 30 s).
 */
final class BroadcastOrderActivity
{
    /** Commandes expirées lues par paquets de cette taille. */
    private const CHUNK = 500;

    /** Canaux `region-day.{regionId}.{day}` et `client.{clientId}`. */
    public function handleOrderSubmitted(OrderSubmitted $event): void
    {
        $this->relayOrder($event, OrderStatus::Submitted, RealtimeOrderSubmitted::class);
    }

    /** Canaux `region-day.{regionId}.{day}` et `client.{clientId}`. */
    public function handleOrderUpdated(OrderUpdated $event): void
    {
        $this->relayOrder($event, OrderStatus::Submitted, RealtimeOrderUpdated::class);
    }

    /** Canaux `region-day.{regionId}.{day}` et `client.{clientId}`. */
    public function handleOrderCancelled(OrderCancelled $event): void
    {
        $this->relayOrder($event, OrderStatus::Cancelled, RealtimeOrderCancelled::class);
    }

    /**
     * Clôture (§4.4) : `OrdersExpired` { day, count } une fois par région sur
     * `region-day.{regionId}.{day}`, `OrderStatusChanged` pour chaque
     * commande sur `client.{clientId}`.
     */
    public function handleOrdersExpired(OrdersExpired $event): void
    {
        $day = BusinessDay::query()->find($event->businessDayId);
        if ($day === null) {
            return;
        }
        $dayKey = $day->dayKey();

        /** @var array<int, int> $counts région → commandes expirées */
        $counts = [];
        foreach (array_chunk($event->orderIds, self::CHUNK) as $ids) {
            $orders = Order::query()
                ->whereKey($ids)
                ->orderBy('id')
                ->get(['id', 'public_id', 'number', 'client_id', 'region_id', 'version']);
            foreach ($orders as $order) {
                $counts[$order->region_id] = ($counts[$order->region_id] ?? 0) + 1;
                $this->relay(fn () => OrderStatusChanged::dispatch(
                    $order->public_id, $order->number, OrderStatus::Expired->value, $order->version, $dayKey, $order->client_id,
                ));
            }
        }

        ksort($counts);
        foreach ($counts as $regionId => $count) {
            $this->relay(fn () => RealtimeOrdersExpired::dispatch($regionId, $dayKey, $count));
        }
    }

    /**
     * Même événement sur deux canaux : la région (avec le client) puis le
     * client. Statut et version de l'événement, pas ceux relus : deux
     * actions rapprochées se diffusent chacune avec leur version.
     *
     * @param  class-string<OrderActivity>  $realtime
     */
    private function relayOrder(OrderSubmitted|OrderUpdated|OrderCancelled $event, OrderStatus $status, string $realtime): void
    {
        $order = Order::query()->with('businessDay')->find($event->orderId, ['id', 'public_id', 'number', 'business_day_id']);
        if ($order === null) {
            return;
        }
        $day = $order->businessDay->dayKey();

        $this->relay(fn () => $realtime::dispatch($order->public_id, $order->number, $status->value, $event->version, $day, $event->clientId, $event->regionId));
        $this->relay(fn () => $realtime::dispatch($order->public_id, $order->number, $status->value, $event->version, $day, $event->clientId));
    }

    private function relay(Closure $dispatch): void
    {
        try {
            $dispatch();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
