<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Quotas d'une région publiés (§9.6), diffusés en tâche de fond aux écrans
 * de la région (régionaux, ADV, Admin) et au suivi ADV de la journée.
 * Identifiants et résumé seulement (EF-NOT-03) : l'application relit les
 * quotas par l'API.
 */
final class QuotaPublished implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  string  $day  journée « AAAA-MM-JJ »
     */
    public function __construct(
        public readonly int $regionId,
        public readonly string $day,
        public readonly int $version,
        public readonly bool $first,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("region-day.{$this->regionId}.{$this->day}"),
            new PrivateChannel("adv.{$this->day}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'QuotaPublished';
    }

    /** @return array{region_id: int, day: string, version: int, first: bool} */
    public function broadcastWith(): array
    {
        return [
            'region_id' => $this->regionId,
            'day' => $this->day,
            'version' => $this->version,
            'first' => $this->first,
        ];
    }
}
