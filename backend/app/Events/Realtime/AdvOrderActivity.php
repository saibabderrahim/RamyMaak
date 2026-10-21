<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Activité d'une commande pour la file et le suivi de l'ADV (N-08, I-80,
 * I-81), diffusée en tâche de fond sur `adv.{day}` : l'identifiant, le statut
 * atteint, la version et la journée, sans région, client, numéro ni quantité.
 * L'ADV et l'Admin relisent ensuite la commande par l'API, filtrée par
 * périmètre (RG-S05).
 */
abstract class AdvOrderActivity implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  string  $id  identifiant exposé (`public_id`)
     * @param  string  $status  statut de la commande (OrderStatus)
     * @param  int  $version  version de la commande après l'événement
     * @param  string  $day  journée « AAAA-MM-JJ »
     */
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly int $version,
        public readonly string $day,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("adv.{$this->day}")];
    }

    /** @return array{id: string, status: string, version: int, day: string} */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'version' => $this->version,
            'day' => $this->day,
        ];
    }
}
