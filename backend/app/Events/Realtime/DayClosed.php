<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Journée clôturée (§4.4) : les écrans ADV de la journée passent en lecture
 * seule après relecture par l'API (§9.6).
 */
final class DayClosed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  string  $day  journée « AAAA-MM-JJ »
     */
    public function __construct(public readonly string $day) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("adv.{$this->day}")];
    }

    public function broadcastAs(): string
    {
        return 'DayClosed';
    }

    /** @return array{day: string} */
    public function broadcastWith(): array
    {
        return ['day' => $this->day];
    }
}
