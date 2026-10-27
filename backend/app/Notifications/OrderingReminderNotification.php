<?php

namespace App\Notifications;

use App\Domain\Days\CutoffKind;

/**
 * N-11 (§7.8, contrat du lot 8 §3) : client notifié de la répartition qui
 * n'a pas encore de commande comptée à l'approche de l'heure limite de
 * commande (I-88). « Rappel : commande avant 13:00 » / « Vous n'avez pas
 * encore commandé pour le 08/10. » Un appui ouvre l'accueil du client.
 */
final class OrderingReminderNotification extends ReminderNotification
{
    public static function cutoffKind(): CutoffKind
    {
        return CutoffKind::Ordering;
    }

    public function type(): NotificationType
    {
        return NotificationType::OrderingReminder;
    }

    public function title(): string
    {
        return __('notifications.ordering_reminder.title', ['time' => $this->time()]);
    }

    public function body(): string
    {
        return __('notifications.ordering_reminder.body', ['day' => $this->dayLabel()]);
    }

    public function url(): ?string
    {
        return '/client';
    }
}
