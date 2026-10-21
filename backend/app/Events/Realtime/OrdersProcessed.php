<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Changement de statut en masse par l'ADV (RG-A02, I-97) : un seul événement
 * par journée régionale (`region-day.{regionId}.{day}`, grille et commandes
 * reçues de la région) et un seul par journée (`adv.{day}`, file et suivi), et
 * non un par commande, pour ne pas faire relire cent fois chaque écran. Journée,
 * statut atteint et nombre seulement ; chaque client est prévenu sur son canal
 * par OrderStatusChanged.
 */
final class OrdersProcessed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  string  $day  journée « AAAA-MM-JJ »
     * @param  string  $status  statut atteint : IN_PREPARATION, PREPARED ou SHIPPED
     * @param  int  $count  commandes passées à ce statut (de la région, ou de la journée)
     * @param  ?int  $regionId  canal `region-day.{regionId}.{day}` ; null : canal `adv.{day}`
     */
    public function __construct(
        public readonly string $day,
        public readonly string $status,
        public readonly int $count,
        public readonly ?int $regionId = null,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->regionId === null ? "adv.{$this->day}" : "region-day.{$this->regionId}.{$this->day}")];
    }

    public function broadcastAs(): string
    {
        return 'OrdersProcessed';
    }

    /** @return array{day: string, status: string, count: int} */
    public function broadcastWith(): array
    {
        return [
            'day' => $this->day,
            'status' => $this->status,
            'count' => $this->count,
        ];
    }
}
