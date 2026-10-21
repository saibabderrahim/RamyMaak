<?php

namespace App\Listeners;

use App\Domain\Days\RegionStaff;
use App\Events\QuotasPublished;
use App\Models\RegionDay;
use App\Notifications\QuotasPublishedNotification;
use App\Notifications\QuotasUpdatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * N-01 à la première publication, N-02 ensuite (§7.8, RG-Q04) : titulaire et
 * suppléants de la région ce jour-là, comptes actifs (RegionStaff). Tâche de
 * fond (EF-NOT-06), rejouable sans double envoi (§9.7).
 */
final class NotifyQuotasPublished implements ShouldQueue
{
    /** Relancée après un échec technique : les notifications déjà parties ne repartent pas. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(private readonly RegionStaff $staff) {}

    public function handle(QuotasPublished $event): void
    {
        $regionDay = RegionDay::query()->with(['businessDay', 'region'])->find($event->regionDayId);
        if ($regionDay === null) {
            return;
        }

        $notification = $event->first
            ? new QuotasPublishedNotification($regionDay, $event->version)
            : new QuotasUpdatedNotification($regionDay, $event->version, $event->changedProducts);

        $notification->sendOnce($this->staff->regionals($regionDay->region_id, $regionDay->businessDay->day));
    }
}
