<?php

namespace App\Listeners;

use App\Domain\Access\Role;
use App\Domain\Days\CutoffKind;
use App\Domain\Days\RegionDayStatus;
use App\Domain\Days\RegionStaff;
use App\Domain\Limits\LimitBook;
use App\Domain\Referentials\ClientStatus;
use App\Events\CutoffExtended;
use App\Models\RegionDay;
use App\Models\User;
use App\Notifications\CutoffExtendedClientNotification;
use App\Notifications\CutoffExtendedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;

/**
 * Heure limite prolongée pour une région (RG-A05) : les régionaux de la
 * région ce jour-là sont prévenus de la nouvelle heure. Prolongation de la
 * commande, répartition envoyée : les comptes des clients actifs de la
 * région le sont aussi (I-59). Tâche de fond (EF-NOT-06), destinataires
 * clients lus par paquets, rejouable sans double envoi (§9.7).
 */
final class NotifyCutoffExtended implements ShouldQueue
{
    /** Destinataires clients lus par paquets de cette taille. */
    private const CHUNK = 100;

    /** Relancée après un échec technique : les notifications déjà parties ne repartent pas. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        private readonly RegionStaff $staff,
        private readonly LimitBook $limits,
    ) {}

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

        if ($event->kind === CutoffKind::Ordering) {
            $this->notifyClients($regionDay);
        }
    }

    /**
     * Comptes actifs de rôle client des clients actifs de la région ce
     * jour-là (région effective, RG-S04), statut relu à l'exécution ; rien
     * tant que la répartition n'est pas envoyée (les clients ne peuvent pas
     * encore commander) ni après la clôture de la région.
     */
    private function notifyClients(RegionDay $regionDay): void
    {
        if (! $regionDay->isDistributionPublished() || $regionDay->status === RegionDayStatus::Closed) {
            return;
        }

        $clients = $this->limits->clientsQuery($regionDay)
            ->where('status', ClientStatus::Active->value)
            ->reorder()
            ->select('id');

        (new CutoffExtendedClientNotification($regionDay))->sendOnce(
            User::query()
                ->where('is_active', true)
                ->whereIn('client_id', $clients)
                // Un compte passé à un autre rôle garde son client : il n'est plus destinataire.
                ->whereHas('roles', fn (Builder $q) => $q->where('name', Role::Client->value))
                ->lazyById(self::CHUNK),
        );
    }
}
