<?php

namespace App\Jobs;

use App\Domain\Push\PushThrottler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Push groupé de la fin d'une fenêtre d'anti-saturation (EF-NOT-02, I-109 ;
 * contrat du lot 8, §2.4), programmé une fois par utilisateur et par groupe.
 * Rejouable sans double envoi : le compteur est remis à zéro sous verrou.
 */
class FlushPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public int $userId, public string $group)
    {
        $this->afterCommit();
    }

    public function handle(PushThrottler $throttler): void
    {
        $throttler->flush($this->userId, $this->group);
    }
}
