<?php

namespace App\Notifications;

use App\Domain\Days\CutoffKind;
use App\Models\RegionDay;
use App\Support\BusinessClock;

/**
 * N-01 (§7.8) : quotas de la région publiés pour la première fois.
 * « Quotas du 08/10 reçus — région Centre » / « À répartir avant 10:00. »
 * (heure limite de répartition applicable à la région, RG-A05 compris).
 */
final class QuotasPublishedNotification extends RegionDayNotification
{
    /** Heure limite de répartition « HH:MM », fuseau métier. */
    public readonly string $distributionCutoff;

    public function __construct(RegionDay $regionDay, public readonly int $version)
    {
        parent::__construct($regionDay);
        $this->distributionCutoff = BusinessClock::toLocal($regionDay->effectiveCutoff(CutoffKind::Distribution))->format('H:i');
    }

    public function type(): NotificationType
    {
        return NotificationType::QuotasPublished;
    }

    public function title(): string
    {
        return __('notifications.quotas_published.title', ['day' => $this->dayLabel(), 'region' => $this->regionName]);
    }

    public function body(): string
    {
        return __('notifications.quotas_published.body', ['time' => $this->distributionCutoff]);
    }

    protected function uniqueKey(): string
    {
        return $this->keyPrefix().'|version:'.$this->version;
    }
}
