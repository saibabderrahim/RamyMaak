<?php

namespace App\Listeners;

use App\Domain\Days\RegionStaff;
use App\Events\CutoffExtended;
use App\Models\RegionDay;
use App\Notifications\CutoffExtendedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Heure limite prolongée pour une région (RG-A05) : les régionaux de la
 * région ce jour-là sont prévenus de la nouvelle heure. Tâche de fond
 * (EF-NOT-06), rejouable sans double envoi (§9.7).
 */
final class NotifyCutoffExtended implements ShouldQueue
{
    /** Relancée après un échec technique : les notifications déjà parties ne repartent pas. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(private readonly RegionStaff $staff) {}

    public function handle(CutoffExtended $event): void
    {
        $regionDay = RegionDay::query()->with(['businessDay', 'region'])->find($event->regionDayId);
        if ($regionDay === null) {
            return;
        }

        // Heure applicable au moment de l'envoi : après deux prolongations
        // rapprochées, la plus tardive.
        (new CutoffExtendedNotification($regionDay, $event->kind))
            ->sendOnce($this->staff->regionals($regionDay->region_id, $regionDay->businessDay->day));
    }
}
