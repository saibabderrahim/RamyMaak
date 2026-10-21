<?php

namespace App\Listeners;

use App\Events\DistributionPublished;
use App\Events\LimitDraftsSaved;
use App\Events\Realtime\DistributionPublished as RealtimeDistributionPublished;
use App\Events\Realtime\LimitDraftsUpdated;
use App\Events\Realtime\LimitsUpdated;
use App\Models\RegionDay;
use Closure;
use Throwable;

/**
 * Relais de la répartition vers le temps réel (§9.6) : chaque événement
 * diffusé ne porte qu'un identifiant et un résumé ; sa diffusion part en
 * tâche de fond (ShouldBroadcast). Exécuté pendant la requête, après la
 * validation de la transaction : un échec du relais est journalisé sans
 * faire échouer l'enregistrement ni l'envoi (repli : rafraîchissement 30 s).
 */
final class BroadcastDistributionActivity
{
    /**
     * Canaux `region-day.{regionId}.{day}` et `adv.{day}` ; `client.{clientId}`
     * de chaque client notifié (N-03 ou N-04).
     */
    public function handleDistributionPublished(DistributionPublished $event): void
    {
        $regionDay = RegionDay::query()->with('businessDay')->find($event->regionDayId);
        if ($regionDay === null) {
            return;
        }
        $day = $regionDay->businessDay->dayKey();

        $this->relay(fn () => RealtimeDistributionPublished::dispatch($regionDay->region_id, $day, $event->version, $event->first));

        $clientIds = array_values(array_map('intval', $event->notifiedClientIds));
        if ($clientIds !== []) {
            $this->relay(fn () => LimitsUpdated::dispatch($clientIds, $day, $event->version));
        }
    }

    /** Canal `region-day.{regionId}.{day}` seulement : un brouillon ne concerne pas les clients. */
    public function handleLimitDraftsSaved(LimitDraftsSaved $event): void
    {
        $regionDay = RegionDay::query()->with('businessDay')->find($event->regionDayId);
        if ($regionDay === null) {
            return;
        }

        $this->relay(fn () => LimitDraftsUpdated::dispatch($regionDay->region_id, $regionDay->businessDay->dayKey(), $event->statusChanged));
    }

    private function relay(Closure $dispatch): void
    {
        try {
            $dispatch();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
