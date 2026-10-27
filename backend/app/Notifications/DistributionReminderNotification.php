<?php

namespace App\Notifications;

use App\Domain\Days\CutoffKind;

/**
 * N-10 (§7.8, contrat du lot 8 §3) : quotas publiés, répartition pas encore
 * envoyée à l'approche de l'heure limite de répartition. « Rappel :
 * répartition du 08/10 — Centre » / « Envoyez la répartition avant 10:00. »
 * Un appui ouvre la répartition de la région ce jour-là.
 */
final class DistributionReminderNotification extends ReminderNotification
{
    public static function cutoffKind(): CutoffKind
    {
        return CutoffKind::Distribution;
    }

    public function type(): NotificationType
    {
        return NotificationType::DistributionReminder;
    }

    public function title(): string
    {
        return __('notifications.distribution_reminder.title', ['day' => $this->dayLabel(), 'region' => $this->regionName]);
    }

    public function body(): string
    {
        return __('notifications.distribution_reminder.body', ['time' => $this->time()]);
    }

    public function url(): ?string
    {
        return '/regional/repartition?day='.$this->day.'&region='.$this->regionId;
    }
}
