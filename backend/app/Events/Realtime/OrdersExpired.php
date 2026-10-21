<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Commandes de la région expirées à la clôture de la journée (§4.4), un
 * événement par région : les commandes reçues et la grille de répartition
 * se relisent (quantités en attente libérées). Journée et nombre seulement ;
 * chaque client est prévenu sur son canal par OrderStatusChanged.
 */
final class OrdersExpired implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  string  $day  journée « AAAA-MM-JJ »
     * @param  int  $count  commandes de la région expirées
     */
    public function __construct(
        public readonly int $regionId,
        public readonly string $day,
        public readonly int $count,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("region-day.{$this->regionId}.{$this->day}")];
    }

    public function broadcastAs(): string
    {
        return 'OrdersExpired';
    }

    /** @return array{day: string, count: int} */
    public function broadcastWith(): array
    {
        return [
            'day' => $this->day,
            'count' => $this->count,
        ];
    }
}
