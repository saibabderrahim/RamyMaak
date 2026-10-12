<?php

namespace App\Listeners;

use App\Domain\Access\Role;
use App\Events\DistributionPublished;
use App\Models\RegionDay;
use App\Models\User;
use App\Notifications\DistributionPublishedNotification;
use App\Notifications\LimitsUpdatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;

/**
 * N-03 au premier envoi de la répartition (RG-B06), N-04 ensuite aux seuls
 * clients impactés (RG-B07) : comptes actifs de rôle client rattachés aux
 * clients retenus par LimitBook. Tâche de fond (EF-NOT-06 : jusqu'à
 * 150 clients d'une région), destinataires lus par paquets, rejouable sans
 * double envoi (§9.7).
 */
final class NotifyDistributionPublished implements ShouldQueue
{
    /** Destinataires lus par paquets de cette taille. */
    private const CHUNK = 100;

    /** Relancée après un échec technique : les notifications déjà parties ne repartent pas. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function handle(DistributionPublished $event): void
    {
        if ($event->notifiedClientIds === []) {
            return;
        }
        $regionDay = RegionDay::query()->with('businessDay')->find($event->regionDayId);
        if ($regionDay === null) {
            return;
        }

        // Heure limite de commande applicable au moment de l'envoi (RG-A05).
        $notification = $event->first
            ? new DistributionPublishedNotification($regionDay, $event->version)
            : new LimitsUpdatedNotification($regionDay, $event->version);

        $notification->sendOnce(
            User::query()
                ->where('is_active', true)
                ->whereIn('client_id', $event->notifiedClientIds)
                // Un compte passé à un autre rôle garde son client : il n'est plus destinataire.
                ->whereHas('roles', fn (Builder $q) => $q->where('name', Role::Client->value))
                ->lazyById(self::CHUNK),
        );
    }
}
