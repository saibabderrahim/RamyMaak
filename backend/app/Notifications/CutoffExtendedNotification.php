<?php

namespace App\Notifications;

use App\Domain\Days\CutoffKind;
use App\Models\RegionDay;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;

/**
 * Heure limite prolongée pour la région (RG-A05 : « les acteurs concernés
 * sont notifiés »). « Heure limite prolongée — région Centre » /
 * « Répartition jusqu'à 11:15. » ou « Commande jusqu'à 13:30. » ;
 * validation (I-71) : « Heure limite de validation prolongée — région
 * Centre » / « Validez jusqu'à 15:30. », un appui ouvre les commandes reçues.
 */
final class CutoffExtendedNotification extends RegionDayNotification
{
    /** Nouvelle heure limite applicable à la région (UTC). */
    public readonly CarbonImmutable $until;

    public function __construct(RegionDay $regionDay, public readonly CutoffKind $kind)
    {
        parent::__construct($regionDay);
        $this->until = $regionDay->effectiveCutoff($kind)->toImmutable()->utc();
    }

    public function type(): NotificationType
    {
        return NotificationType::CutoffExtended;
    }

    public function title(): string
    {
        $key = $this->kind === CutoffKind::Validation ? 'validation_title' : 'title';

        return __('notifications.cutoff_extended.'.$key, ['region' => $this->regionName]);
    }

    public function body(): string
    {
        return __('notifications.cutoff_extended.body.'.$this->kind->value, ['time' => BusinessClock::toLocal($this->until)->format('H:i')]);
    }

    /** Validation : la liste des commandes reçues ; sinon les quotas du jour. */
    public function url(): ?string
    {
        return $this->kind === CutoffKind::Validation ? '/regional/commandes' : parent::url();
    }

    /** Une notification par nouvelle heure : deux prolongations successives se notifient chacune. */
    protected function uniqueKey(): string
    {
        return $this->keyPrefix().'|'.$this->kind->value.'|until:'.$this->until->format('Y-m-d\TH:i');
    }
}
