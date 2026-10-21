<?php

namespace App\Listeners;

use App\Domain\Orders\OrderStatus;
use App\Events\OrderCancelled;
use App\Events\OrderCancelledByAdv;
use App\Events\OrderRejected;
use App\Events\OrdersExpired;
use App\Events\OrderShippingUpdated;
use App\Events\OrdersProcessed;
use App\Events\OrderSubmitted;
use App\Events\OrderUpdated;
use App\Events\OrderValidated;
use App\Events\Realtime\AdvOrderActivity;
use App\Events\Realtime\AdvOrderCancelledByAdv;
use App\Events\Realtime\AdvOrderShippingUpdated;
use App\Events\Realtime\AdvOrderValidated;
use App\Events\Realtime\OrderActivity;
use App\Events\Realtime\OrderCancelled as RealtimeOrderCancelled;
use App\Events\Realtime\OrderCancelledByAdv as RealtimeOrderCancelledByAdv;
use App\Events\Realtime\OrderRejected as RealtimeOrderRejected;
use App\Events\Realtime\OrdersExpired as RealtimeOrdersExpired;
use App\Events\Realtime\OrdersProcessed as RealtimeOrdersProcessed;
use App\Events\Realtime\OrderStatusChanged;
use App\Events\Realtime\OrderSubmitted as RealtimeOrderSubmitted;
use App\Events\Realtime\OrderUpdated as RealtimeOrderUpdated;
use App\Events\Realtime\OrderValidated as RealtimeOrderValidated;
use App\Models\BusinessDay;
use App\Models\Order;
use Closure;
use Throwable;

/**
 * Relais des commandes vers le temps réel (§9.6 ; contrats des lots 5 et 6,
 * §5 et §7) : chaque événement diffusé ne porte que des identifiants, sans
 * quantité ; sa diffusion part en tâche de fond (ShouldBroadcast). Exécuté
 * pendant la requête, après la validation de la transaction : un échec du
 * relais est journalisé sans faire échouer l'envoi, la modification,
 * l'annulation, la décision, le traitement ou la clôture déjà enregistrés
 * (repli : rafraîchissement 30 s).
 */
final class BroadcastOrderActivity
{
    /** Commandes lues par paquets de cette taille (clôture, statuts en masse). */
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
     * Validation (lot 6) : `OrderValidated` avec le client sur
     * `region-day.{regionId}.{day}`, `OrderStatusChanged` sur
     * `client.{clientId}`, `OrderValidated` sans région ni client sur
     * `adv.{day}` (N-08, I-80, I-81).
     */
    public function handleOrderValidated(OrderValidated $event): void
    {
        $this->relayDecision($event, OrderStatus::Validated, RealtimeOrderValidated::class, AdvOrderValidated::class);
    }

    /** Rejet (lot 6) : canaux `region-day.{regionId}.{day}` et `client.{clientId}`, rien pour l'ADV. */
    public function handleOrderRejected(OrderRejected $event): void
    {
        $this->relayDecision($event, OrderStatus::Rejected, RealtimeOrderRejected::class);
    }

    /**
     * Annulation exceptionnelle par l'ADV (lot 6) : `OrderCancelledByAdv` sur
     * `region-day.{regionId}.{day}` (V restitué) et sur `adv.{day}`,
     * `OrderStatusChanged` sur `client.{clientId}`. Jamais `OrderCancelled`,
     * réservé à l'annulation par le client.
     */
    public function handleOrderCancelledByAdv(OrderCancelledByAdv $event): void
    {
        $this->relayDecision($event, OrderStatus::Cancelled, RealtimeOrderCancelledByAdv::class, AdvOrderCancelledByAdv::class);
    }

    /** Saisie de l'expédition (lot 6) : canal `adv.{day}` seulement, sans notification (I-79). */
    public function handleOrderShippingUpdated(OrderShippingUpdated $event): void
    {
        $order = Order::query()->with('businessDay')->find($event->orderId, ['id', 'public_id', 'status', 'business_day_id']);
        if ($order === null) {
            return;
        }
        $day = $order->businessDay->dayKey();

        $this->relay(fn () => AdvOrderShippingUpdated::dispatch($order->public_id, $order->status->value, $event->version, $day));
    }

    /**
     * Changement de statut en masse (lot 6, I-97) : `OrderStatusChanged` pour
     * chaque commande sur `client.{clientId}`, puis un seul `OrdersProcessed`
     * { day, status, count } par journée régionale sur
     * `region-day.{regionId}.{day}` et un seul par journée sur `adv.{day}`.
     * Statut et version de l'événement : une commande déjà passée plus loin
     * se diffuse avec sa version d'alors.
     */
    public function handleOrdersProcessed(OrdersProcessed $event): void
    {
        if ($event->orders === []) {
            return;
        }
        $status = $event->to->value;
        $days = BusinessDay::query()
            ->whereKey(array_values(array_unique(array_column($event->orders, 'businessDayId'))))
            ->get(['id', 'day'])
            ->mapWithKeys(fn (BusinessDay $day) => [$day->id => $day->dayKey()]);

        /** @var array<string, int> $perDay journée → commandes */
        $perDay = [];
        /** @var array<string, array<int, int>> $perRegion journée → région → commandes */
        $perRegion = [];
        $items = collect($event->orders)->sortBy('orderId')->values()->all();
        foreach (array_chunk($items, self::CHUNK) as $chunk) {
            $orders = Order::query()
                ->whereKey(array_column($chunk, 'orderId'))
                ->get(['id', 'public_id', 'number'])
                ->keyBy('id');
            foreach ($chunk as $item) {
                $order = $orders->get($item['orderId']);
                $day = $days->get($item['businessDayId']);
                if ($order === null || $day === null) {
                    continue;
                }
                $perDay[$day] = ($perDay[$day] ?? 0) + 1;
                $perRegion[$day][$item['regionId']] = ($perRegion[$day][$item['regionId']] ?? 0) + 1;
                $this->relay(fn () => OrderStatusChanged::dispatch(
                    $order->public_id, $order->number, $status, $item['version'], $day, $item['clientId'],
                ));
            }
        }

        ksort($perDay);
        ksort($perRegion);
        foreach ($perRegion as $day => $regions) {
            ksort($regions);
            foreach ($regions as $regionId => $count) {
                $this->relay(fn () => RealtimeOrdersProcessed::dispatch($day, $status, $count, $regionId));
            }
        }
        foreach ($perDay as $day => $count) {
            $this->relay(fn () => RealtimeOrdersProcessed::dispatch($day, $status, $count));
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

    /**
     * Décision sur une commande (lot 6) : l'événement de la région (avec le
     * client), `OrderStatusChanged` pour le client, puis, si la file de l'ADV
     * est concernée, son événement (identifiants seulement, I-81). Statut et
     * version de l'événement, pas ceux relus.
     *
     * @param  class-string<OrderActivity>  $realtime
     * @param  class-string<AdvOrderActivity>|null  $advRealtime
     */
    private function relayDecision(OrderValidated|OrderRejected|OrderCancelledByAdv $event, OrderStatus $status, string $realtime, ?string $advRealtime = null): void
    {
        $order = Order::query()->with('businessDay')->find($event->orderId, ['id', 'public_id', 'number', 'business_day_id']);
        if ($order === null) {
            return;
        }
        $day = $order->businessDay->dayKey();

        $this->relay(fn () => $realtime::dispatch($order->public_id, $order->number, $status->value, $event->version, $day, $event->clientId, $event->regionId));
        $this->relay(fn () => OrderStatusChanged::dispatch($order->public_id, $order->number, $status->value, $event->version, $day, $event->clientId));
        if ($advRealtime !== null) {
            $this->relay(fn () => $advRealtime::dispatch($order->public_id, $status->value, $event->version, $day));
        }
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
