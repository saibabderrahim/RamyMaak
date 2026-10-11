<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Brouillons de quotas enregistrés (saisie, copie ou import, RG-Q03) : les
 * écrans ADV ouverts sur la journée relisent la matrice (§9.6). Les
 * brouillons ne sont jamais diffusés aux régionaux.
 */
final class DraftsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  list<int>  $regionIds  régions dont au moins une cellule a changé
     * @param  string  $day  journée « AAAA-MM-JJ »
     */
    public function __construct(
        public readonly array $regionIds,
        public readonly string $day,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("adv.{$this->day}")];
    }

    public function broadcastAs(): string
    {
        return 'DraftsUpdated';
    }

    /** @return array{region_ids: list<int>, day: string} */
    public function broadcastWith(): array
    {
        return [
            'region_ids' => $this->regionIds,
            'day' => $this->day,
        ];
    }
}
