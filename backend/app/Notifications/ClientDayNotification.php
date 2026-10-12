<?php

namespace App\Notifications;

use App\Models\RegionDay;
use App\Support\BusinessClock;

/**
 * Notification adressée aux comptes d'un client pour une journée (§7.8 :
 * N-03, N-04), à propos de la répartition de sa région. Un appui ouvre
 * l'accueil du client (EF-NOT-03) ; son écran de commande arrive au lot 5.
 * Une notification par journée régionale, version et destinataire (§9.7).
 */
abstract class ClientDayNotification extends AppNotification
{
    // Pas de « readonly » ici : SerializesModels (file de diffusion) ne sait
    // pas restaurer une propriété en lecture seule déclarée par une classe
    // parente.
    public int $regionDayId;

    /** Journée « AAAA-MM-JJ ». */
    public string $day;

    /** Version de la répartition envoyée (`distribution_version`). */
    public int $version;

    /** Journée régionale chargée avec sa journée. */
    public function __construct(RegionDay $regionDay, int $version)
    {
        $this->regionDayId = $regionDay->id;
        $this->day = $regionDay->businessDay->dayKey();
        $this->version = $version;
    }

    public function url(): ?string
    {
        return '/client';
    }

    /** Journée dans les textes : « 08/10 ». */
    protected function dayLabel(): string
    {
        return BusinessClock::parseDay($this->day)?->format('d/m') ?? $this->day;
    }

    protected function uniqueKey(): string
    {
        return $this->type()->value.'|region_day:'.$this->regionDayId.'|version:'.$this->version;
    }
}
