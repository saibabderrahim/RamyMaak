<?php

namespace App\Console\Commands;

use App\Domain\Reminders\Reminders;
use Illuminate\Console\Command;

/**
 * Rappels N-10 à N-12 et alerte N-13 (contrat du lot 8, §3) : programme
 * une tâche de fond par rappel dont la fenêtre est ouverte et la condition
 * remplie. Lancée chaque minute par le planificateur ; rejouable sans
 * double envoi (une notification par heure limite effective, I-107).
 */
class SendReminders extends Command
{
    protected $signature = 'ramymaak:send-reminders';

    protected $description = 'Envoie les rappels N-10 à N-12 et l\'alerte N-13 dont la fenêtre est ouverte';

    public function handle(Reminders $reminders): int
    {
        $dispatched = $reminders->dispatchDue();
        $this->info("{$dispatched} rappel(s) programmé(s).");

        return self::SUCCESS;
    }
}
