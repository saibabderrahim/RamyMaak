<?php

namespace App\Listeners;

use App\Events\DayClosed;
use App\Events\QuotaDraftsSaved;
use App\Events\QuotasPublished;
use App\Events\Realtime\DayClosed as RealtimeDayClosed;
use App\Events\Realtime\DraftsUpdated;
use App\Events\Realtime\QuotaPublished;
use App\Models\BusinessDay;
use App\Models\RegionDay;
use Closure;
use Throwable;

/**
 * Relais des événements de domaine vers le temps réel (§9.6) : chaque
 * événement diffusé ne porte qu'un identifiant et un résumé ; sa diffusion
 * part en tâche de fond (ShouldBroadcast). Exécuté pendant la requête, après
 * la validation de la transaction : un échec du relais est journalisé sans
 * faire échouer l'action déjà enregistrée (repli : rafraîchissement 30 s).
 */
final class BroadcastDayActivity
{
    /** Canaux `region-day.{regionId}.{day}` et `adv.{day}`. */
    public function handleQuotasPublished(QuotasPublished $event): void
    {
        $regionDay = RegionDay::query()->with('businessDay')->find($event->regionDayId);
        if ($regionDay === null) {
            return;
        }

        $this->relay(fn () => QuotaPublished::dispatch($regionDay->region_id, $regionDay->businessDay->dayKey(), $event->version, $event->first));
    }

    /** Canal `adv.{day}` seulement : les brouillons ne concernent que l'ADV (RG-Q03). */
    public function handleQuotaDraftsSaved(QuotaDraftsSaved $event): void
    {
        $day = BusinessDay::query()->find($event->businessDayId);
        if ($day === null) {
            return;
        }

        $this->relay(fn () => DraftsUpdated::dispatch(array_values(array_map('intval', $event->regionIds)), $day->dayKey()));
    }

    /** Canal `adv.{day}`. */
    public function handleDayClosed(DayClosed $event): void
    {
        $day = BusinessDay::query()->find($event->businessDayId);
        if ($day === null) {
            return;
        }

        $this->relay(fn () => RealtimeDayClosed::dispatch($day->dayKey()));
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
