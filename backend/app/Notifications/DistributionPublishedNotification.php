<?php

namespace App\Notifications;

use App\Domain\Days\CutoffKind;
use App\Models\RegionDay;
use App\Support\BusinessClock;

/**
 * N-03 (§7.8, RG-B06) : répartition de la région envoyée pour la première
 * fois, la prise de commande est ouverte. « Quotas du 08/10 disponibles » /
 * « Vos quotas du 08/10 sont disponibles. Commandez avant 13:00. » (heure
 * limite de commande applicable à la région, RG-A05 compris).
 */
final class DistributionPublishedNotification extends ClientDayNotification
{
    /** Heure limite de commande « HH:MM », fuseau métier. */
    public readonly string $orderingCutoff;

    /** Journée régionale chargée avec sa journée. */
    public function __construct(RegionDay $regionDay, int $version)
    {
        parent::__construct($regionDay, $version);
        $this->orderingCutoff = BusinessClock::toLocal($regionDay->effectiveCutoff(CutoffKind::Ordering))->format('H:i');
    }

    public function type(): NotificationType
    {
        return NotificationType::DistributionPublished;
    }

    public function title(): string
    {
        return __('notifications.distribution_published.title', ['day' => $this->dayLabel()]);
    }

    public function body(): string
    {
        return __('notifications.distribution_published.body', ['day' => $this->dayLabel(), 'time' => $this->orderingCutoff]);
    }
}
