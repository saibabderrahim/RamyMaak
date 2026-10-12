<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Bornes enregistrées en brouillon (saisie, « Reprendre J-1 », RG-B08,
 * RG-B09) : les autres écrans ouverts sur la répartition de la région relisent
 * la grille (§9.6). Jamais diffusé aux clients : un brouillon ne les concerne
 * qu'une fois envoyé. Diffusé aussi sur `adv.{day}` au premier brouillon
 * seulement (statut « Répartition en cours »), pas à chaque saisie.
 */
final class LimitDraftsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  string  $day  journée « AAAA-MM-JJ »
     */
    public function __construct(
        public readonly int $regionId,
        public readonly string $day,
        public readonly bool $statusChanged = false,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel("region-day.{$this->regionId}.{$this->day}")];
        if ($this->statusChanged) {
            $channels[] = new PrivateChannel("adv.{$this->day}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'LimitDraftsUpdated';
    }

    /** @return array{region_id: int, day: string} */
    public function broadcastWith(): array
    {
        return [
            'region_id' => $this->regionId,
            'day' => $this->day,
        ];
    }
}
