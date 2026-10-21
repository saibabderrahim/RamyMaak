<?php

namespace App\Notifications;

use App\Domain\Days\CutoffKind;
use App\Models\RegionDay;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;

/**
 * Heure limite de commande prolongée pour la région (RG-A05, I-59), annoncée
 * aux comptes des clients actifs de la région quand la répartition est
 * envoyée : « Heure limite de commande prolongée » / « Commandez jusqu'à
 * 13:30. » (heure applicable au moment de l'envoi). Un appui ouvre l'accueil
 * du client.
 */
final class CutoffExtendedClientNotification extends ClientDayNotification
{
    /** Nouvelle heure limite de commande applicable à la région (UTC). */
    public readonly CarbonImmutable $until;

    /** Journée régionale chargée avec sa journée. */
    public function __construct(RegionDay $regionDay)
    {
        parent::__construct($regionDay, $regionDay->distribution_version);
        $this->until = $regionDay->effectiveCutoff(CutoffKind::Ordering)->toImmutable()->utc();
    }

    public function type(): NotificationType
    {
        return NotificationType::CutoffExtended;
    }

    public function title(): string
    {
        return __('notifications.cutoff_extended_client.title');
    }

    public function body(): string
    {
        return __('notifications.cutoff_extended_client.body', ['time' => BusinessClock::toLocal($this->until)->format('H:i')]);
    }

    /**
     * Une notification par nouvelle heure, quelle que soit la version de la
     * répartition : deux prolongations successives se notifient chacune.
     */
    protected function uniqueKey(): string
    {
        return $this->type()->value.'|clients|region_day:'.$this->regionDayId.'|'.CutoffKind::Ordering->value.'|until:'.$this->until->format('Y-m-d\TH:i');
    }
}
