<?php

namespace App\Domain\Reminders;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Envoi d'un rappel pour une journée régionale, en tâche de fond
 * (EF-NOT-06) : programmée chaque minute par `ramymaak:send-reminders`
 * tant que le rappel est dû. Conditions, fenêtre et destinataires sont
 * relus à l'exécution ; rejouable sans double envoi (§9.7).
 */
final class SendReminder implements ShouldQueue
{
    use Queueable;

    /** Relancée après un échec technique : les notifications déjà parties ne repartent pas. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public int $regionDayId, public Reminder $reminder) {}

    public function handle(Reminders $reminders): void
    {
        $reminders->send($this->regionDayId, $this->reminder);
    }
}
