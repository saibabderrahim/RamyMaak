<?php

namespace App\Notifications;

use App\Domain\Days\CutoffKind;
use App\Models\RegionDay;

/**
 * N-12 (§7.8, contrat du lot 8 §3) : commandes encore envoyées dans la
 * région (région figée à l'envoi) à l'approche de l'heure limite de
 * validation (I-71). « Rappel : 3 commandes à valider » / « Centre : validez
 * avant 15:00. » Un appui ouvre les commandes reçues du jour.
 */
final class ValidationReminderNotification extends ReminderNotification
{
    /** Journée régionale chargée avec sa journée et sa région ; `$count` : commandes envoyées. */
    public function __construct(RegionDay $regionDay, public readonly int $count)
    {
        parent::__construct($regionDay);
    }

    public static function cutoffKind(): CutoffKind
    {
        return CutoffKind::Validation;
    }

    public function type(): NotificationType
    {
        return NotificationType::ValidationReminder;
    }

    public function title(): string
    {
        return trans_choice('notifications.validation_reminder.title', $this->count, ['count' => $this->count]);
    }

    public function body(): string
    {
        return __('notifications.validation_reminder.body', ['region' => $this->regionName, 'time' => $this->time()]);
    }

    public function url(): ?string
    {
        return '/regional/commandes?day='.$this->day;
    }
}
