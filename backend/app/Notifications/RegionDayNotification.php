<?php

namespace App\Notifications;

use App\Models\RegionDay;
use App\Support\BusinessClock;

/**
 * Notification portant sur une région pour une journée (§7.8), adressée aux
 * régionaux de la région ce jour-là ; un appui ouvre les quotas du jour
 * concerné (EF-NOT-03).
 */
abstract class RegionDayNotification extends AppNotification
{
    // Pas de « readonly » ici : SerializesModels (file de diffusion) ne sait
    // pas restaurer une propriété en lecture seule déclarée par une classe
    // parente.
    public int $regionDayId;

    /** Journée « AAAA-MM-JJ ». */
    public string $day;

    public string $regionName;

    /** Journée régionale chargée avec sa journée et sa région. */
    public function __construct(RegionDay $regionDay)
    {
        $this->regionDayId = $regionDay->id;
        $this->day = $regionDay->businessDay->dayKey();
        $this->regionName = $regionDay->region->name;
    }

    public function url(): ?string
    {
        return '/regional/quotas?day='.$this->day;
    }

    /** Journée dans les textes : « 08/10 ». */
    protected function dayLabel(): string
    {
        return BusinessClock::parseDay($this->day)?->format('d/m') ?? $this->day;
    }

    protected function keyPrefix(): string
    {
        return $this->type()->value.'|region_day:'.$this->regionDayId;
    }
}
