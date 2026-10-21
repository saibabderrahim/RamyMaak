<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Activité d'une commande (§9.6 ; contrat du lot 5, §5), diffusée en tâche
 * de fond sur un seul canal : `region-day.{regionId}.{day}` pour les écrans
 * de la région ce jour-là (régionaux, ADV, Admin ; commandes reçues, grille
 * de répartition), avec le client, ou `client.{clientId}` pour les comptes
 * du client. Identifiants seulement, aucune quantité ni montant
 * (EF-NOT-03) : l'application relit la commande par l'API.
 */
abstract class OrderActivity implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  string  $id  identifiant exposé (`public_id`)
     * @param  string  $number  numéro « RMK-AAMMJJ-NNNNN »
     * @param  string  $status  statut après l'événement (OrderStatus)
     * @param  int  $version  version de la commande après l'événement
     * @param  string  $day  journée « AAAA-MM-JJ »
     * @param  ?int  $regionId  canal `region-day.{regionId}.{day}` ; null : canal `client.{clientId}`
     */
    public function __construct(
        public readonly string $id,
        public readonly string $number,
        public readonly string $status,
        public readonly int $version,
        public readonly string $day,
        public readonly int $clientId,
        public readonly ?int $regionId = null,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $channel = $this->regionId === null ? "client.{$this->clientId}" : "region-day.{$this->regionId}.{$this->day}";

        return [new PrivateChannel($channel)];
    }

    /**
     * Le client n'est nommé que sur le canal de la région : sur le sien, il
     * est implicite.
     *
     * @return array{id: string, number: string, status: string, version: int, day: string, client_id?: int}
     */
    public function broadcastWith(): array
    {
        $payload = [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'version' => $this->version,
            'day' => $this->day,
        ];
        if ($this->regionId !== null) {
            $payload['client_id'] = $this->clientId;
        }

        return $payload;
    }
}
