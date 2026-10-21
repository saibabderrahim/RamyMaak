<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Bornes d'un client publiées ou modifiées (RG-B06, RG-B07), diffusées en
 * tâche de fond sur le canal de chaque client notifié (§9.6 :
 * `client.{clientId}`). Un seul événement pour tous ces canaux : le
 * diffuseur (protocole Pusher) les envoie par paquets de 100. Journée et
 * version seulement (EF-NOT-03) : l'application du client relit ses bornes
 * par l'API (lot 5).
 */
final class LimitsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  list<int>  $clientIds  clients notifiés
     * @param  string  $day  journée « AAAA-MM-JJ »
     * @param  int  $version  `distribution_version` après l'envoi
     */
    public function __construct(
        public readonly array $clientIds,
        public readonly string $day,
        public readonly int $version,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(fn (int $clientId) => new PrivateChannel("client.{$clientId}"), $this->clientIds);
    }

    public function broadcastAs(): string
    {
        return 'LimitsUpdated';
    }

    /** @return array{day: string, version: int} */
    public function broadcastWith(): array
    {
        return [
            'day' => $this->day,
            'version' => $this->version,
        ];
    }
}
