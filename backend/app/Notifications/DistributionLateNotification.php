<?php

namespace App\Notifications;

use App\Domain\Days\CutoffKind;
use App\Models\RegionDay;

/**
 * N-13 (§7.8, contrat du lot 8 §3) : heure limite de répartition passée,
 * quotas publiés mais répartition pas envoyée ; jusqu'à la clôture, aux ADV
 * dont le périmètre comprend la région ce jour-là et aux Admin. Alerte, non
 * coupable par les préférences (EF-NOT-04), dans l'application seulement.
 * « Répartition en retard — Centre » / « La répartition du 08/10 n'est pas
 * envoyée (limite 10:00). » Un appui ouvre le suivi de la journée, dans
 * l'espace du destinataire (`/adv` ou `/admin`).
 */
final class DistributionLateNotification extends ReminderNotification
{
    /** Journée régionale chargée avec sa journée et sa région ; `$space` : « /adv » ou « /admin ». */
    public function __construct(RegionDay $regionDay, public readonly string $space = '/adv')
    {
        parent::__construct($regionDay);
    }

    public static function cutoffKind(): CutoffKind
    {
        return CutoffKind::Distribution;
    }

    public function type(): NotificationType
    {
        return NotificationType::DistributionLate;
    }

    public function title(): string
    {
        return __('notifications.distribution_late.title', ['region' => $this->regionName]);
    }

    public function body(): string
    {
        return __('notifications.distribution_late.body', ['day' => $this->dayLabel(), 'time' => $this->time()]);
    }

    public function url(): ?string
    {
        return $this->space.'/journees/'.$this->day.'/suivi';
    }
}
