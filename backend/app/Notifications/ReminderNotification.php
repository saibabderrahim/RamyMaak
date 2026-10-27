<?php

namespace App\Notifications;

use App\Domain\Days\CutoffKind;
use App\Models\RegionDay;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;

/**
 * Rappel ou alerte du planificateur portant sur une journée régionale et une
 * heure limite (N-10 à N-13, contrat du lot 8 §3). L'heure annoncée est
 * l'heure **effective** de la région, prolongation comprise (RG-A05).
 *
 * Une fois par heure limite (I-107) : la clé d'unicité porte le type, la
 * journée régionale et l'heure limite effective ; une prolongation en
 * déclenche donc un nouveau.
 */
abstract class ReminderNotification extends RegionDayNotification
{
    // Pas de « readonly » ici : SerializesModels (file de diffusion) ne sait
    // pas restaurer une propriété en lecture seule déclarée par une classe
    // parente.
    public int $regionId;

    /** Heure limite effective visée, UTC « AAAA-MM-JJTHH:MM ». */
    public string $cutoffAt;

    /** Journée régionale chargée avec sa journée et sa région. */
    public function __construct(RegionDay $regionDay)
    {
        parent::__construct($regionDay);
        $this->regionId = $regionDay->region_id;
        $this->cutoffAt = $regionDay->effectiveCutoff(static::cutoffKind())->toImmutable()->utc()->format('Y-m-d\TH:i');
    }

    /** Heure limite visée par le rappel. */
    abstract public static function cutoffKind(): CutoffKind;

    /** Heure limite dans les textes : « HH:MM », fuseau métier. */
    protected function time(): string
    {
        return BusinessClock::toLocal(CarbonImmutable::createFromFormat('Y-m-d\TH:i', $this->cutoffAt, 'UTC'))->format('H:i');
    }

    protected function uniqueKey(): string
    {
        return $this->keyPrefix().'|cutoff:'.$this->cutoffAt;
    }
}
