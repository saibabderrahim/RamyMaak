<?php

namespace App\Domain\Reminders;

use App\Domain\Access\Perimeter;
use App\Domain\Access\Role;
use App\Domain\Days\BusinessDayStatus;
use App\Domain\Days\RegionDayStatus;
use App\Domain\Days\RegionStaff;
use App\Domain\Orders\OrderStatus;
use App\Domain\Orders\RegionalDayOverview;
use App\Models\Order;
use App\Models\RegionDay;
use App\Models\User;
use App\Notifications\ClientOrderNotification;
use App\Notifications\DistributionLateNotification;
use App\Notifications\DistributionReminderNotification;
use App\Notifications\OrderingReminderNotification;
use App\Notifications\ReminderNotification;
use App\Notifications\ValidationReminderNotification;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\LazyCollection;

/**
 * Rappels N-10 à N-12 et alerte N-13 (contrat du lot 8, §3, I-107), lancés
 * chaque minute par `ramymaak:send-reminders` sur les journées régionales
 * non clôturées des journées ouvertes, régions actives.
 *
 * | Rappel | Condition                                  | Destinataires                                          |
 * |--------|--------------------------------------------|--------------------------------------------------------|
 * | N-10   | quotas publiés, répartition non envoyée    | régionaux de la région ce jour-là (RegionStaff)        |
 * | N-11   | répartition envoyée                        | comptes clients des clients sans commande (I-88)       |
 * | N-12   | commandes Envoyées de la région ce jour-là | régionaux de la région ce jour-là                      |
 * | N-13   | quotas publiés, répartition non envoyée    | ADV dont le périmètre comprend la région, et Admin     |
 *
 * - **Fenêtres** : Reminder::window(), sur l'heure limite effective de la
 *   région ; hors fenêtre, rien (un planificateur relancé après l'heure
 *   limite n'envoie pas de rappel en retard).
 * - **Une fois par heure limite** : clé d'unicité de la notification
 *   (type, journée régionale, heure limite effective) ; une prolongation en
 *   déclenche un nouveau.
 * - **Préférences** : N-10 à N-12 écartent les comptes qui ont coupé les
 *   rappels (EF-NOT-04) ; N-13 n'est pas coupable.
 * - **Comptes actifs** seulement ; destinataires et conditions relus à
 *   l'exécution de la tâche (EF-NOT-06), qui revérifie aussi la fenêtre.
 */
final class Reminders
{
    /** Destinataires traités par paquets de cette taille. */
    private const CHUNK = 100;

    public function __construct(
        private readonly RegionStaff $staff,
        private readonly RegionalDayOverview $overview,
        private readonly Perimeter $perimeter,
    ) {}

    /**
     * Programme une tâche de fond par rappel dû maintenant : la commande du
     * planificateur reste rapide quel que soit le nombre de destinataires.
     *
     * @return int tâches programmées
     */
    public function dispatchDue(?CarbonInterface $now = null): int
    {
        $now = ($now ?? now())->toImmutable()->utc();
        $dispatched = 0;
        foreach ($this->candidates($now) as $regionDay) {
            foreach (Reminder::cases() as $reminder) {
                if ($this->isDue($regionDay, $reminder, $now)) {
                    SendReminder::dispatch($regionDay->id, $reminder);
                    $dispatched++;
                }
            }
        }

        return $dispatched;
    }

    /**
     * Rappel dû : journée ouverte, journée régionale non clôturée, instant
     * dans la fenêtre et condition remplie. Journée régionale chargée avec
     * sa journée.
     */
    public function isDue(RegionDay $regionDay, Reminder $reminder, CarbonInterface $now): bool
    {
        if (! $regionDay->businessDay->isOpen() || $regionDay->status === RegionDayStatus::Closed) {
            return false;
        }
        [$start, $end] = $reminder->window($regionDay);
        if ($now->lessThan($start) || $now->greaterThanOrEqualTo($end)) {
            return false;
        }

        return match ($reminder) {
            Reminder::Distribution, Reminder::DistributionLate => $regionDay->isQuotaPublished() && ! $regionDay->isDistributionPublished(),
            Reminder::Ordering => $regionDay->isDistributionPublished(),
            Reminder::Validation => $this->submittedOrders($regionDay) > 0,
        };
    }

    /**
     * Envoie un rappel s'il est encore dû (tâche de fond) : rien si la
     * journée a changé entre-temps (répartition envoyée, prolongation,
     * clôture) ou si la fenêtre est passée.
     *
     * @return int notifications envoyées
     */
    public function send(int $regionDayId, Reminder $reminder, ?CarbonInterface $now = null): int
    {
        $regionDay = RegionDay::query()->with(['businessDay', 'region'])->find($regionDayId);
        if ($regionDay === null || ! $this->isDue($regionDay, $reminder, ($now ?? now())->toImmutable()->utc())) {
            return 0;
        }

        return match ($reminder) {
            Reminder::Distribution => $this->deliver($reminder, new DistributionReminderNotification($regionDay), $this->regionals($regionDay)),
            Reminder::Ordering => $this->deliver($reminder, new OrderingReminderNotification($regionDay), $this->clientAccountsWithoutOrder($regionDay)),
            Reminder::Validation => $this->deliver($reminder, new ValidationReminderNotification($regionDay, $this->submittedOrders($regionDay)), $this->regionals($regionDay)),
            Reminder::DistributionLate => $this->sendLate($regionDay),
        };
    }

    /**
     * Journées régionales non clôturées des journées ouvertes avant leur
     * clôture, régions actives.
     *
     * @return Collection<int, RegionDay>
     */
    private function candidates(CarbonImmutable $now): Collection
    {
        return RegionDay::query()
            ->where('status', '!=', RegionDayStatus::Closed->value)
            ->whereHas('businessDay', fn (Builder $q) => $q
                ->where('status', BusinessDayStatus::Open->value)
                ->where('closing_at', '>', $now))
            ->whereHas('region', fn (Builder $q) => $q->where('is_active', true))
            ->with(['businessDay', 'region'])
            ->orderBy('id')
            ->get();
    }

    /** Commandes encore envoyées de la région ce jour-là (région figée à l'envoi). */
    private function submittedOrders(RegionDay $regionDay): int
    {
        return Order::query()
            ->where('business_day_id', $regionDay->business_day_id)
            ->where('region_id', $regionDay->region_id)
            ->where('status', OrderStatus::Submitted->value)
            ->count();
    }

    /**
     * Titulaire et suppléants actifs de la région ce jour-là.
     *
     * @return SupportCollection<int, User>
     */
    private function regionals(RegionDay $regionDay): SupportCollection
    {
        return $this->staff->regionals($regionDay->region_id, $regionDay->businessDay->day);
    }

    /**
     * Comptes actifs de rôle client des clients notifiés sans commande
     * comptée (« Clients sans commande », I-88), lus par paquets.
     *
     * @return LazyCollection<int, User>
     */
    private function clientAccountsWithoutOrder(RegionDay $regionDay): LazyCollection
    {
        $clientIds = $this->overview->clientIdsWithoutOrder($regionDay);

        return $clientIds === []
            ? LazyCollection::empty()
            : ClientOrderNotification::recipientsQuery($clientIds)->lazyById(self::CHUNK);
    }

    /**
     * N-13 : ADV actifs dont le périmètre comprend la région ce jour-là
     * (RG-S05) et Admin actifs, chacun vers le suivi de son espace.
     */
    private function sendLate(RegionDay $regionDay): int
    {
        $day = $regionDay->businessDay->day;
        $recipients = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $q) => $q->whereIn('name', [Role::Adv->value, Role::Admin->value]))
            ->with('roles')
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user) => $this->perimeter->canAccessRegion($user, $regionDay->region_id, $day));

        $sent = 0;
        foreach ([Role::Adv->value => '/adv', Role::Admin->value => '/admin'] as $role => $space) {
            $sent += $this->deliver(
                Reminder::DistributionLate,
                new DistributionLateNotification($regionDay, $space),
                $recipients->filter(fn (User $user) => $user->appRole()?->value === $role),
            );
        }

        return $sent;
    }

    /**
     * Envoie aux destinataires qui ne l'ont pas encore reçue. Chaque minute
     * de la fenêtre relit les destinataires (un suppléant arrivé, un client
     * notifié depuis) : ceux déjà servis sont écartés par paquets, en une
     * requête par paquet ; sendOnce revérifie chacun.
     *
     * @param  iterable<User>  $recipients
     */
    private function deliver(Reminder $reminder, ReminderNotification $notification, iterable $recipients): int
    {
        $sent = 0;
        foreach (LazyCollection::make(fn () => yield from $recipients)->chunk(self::CHUNK) as $chunk) {
            $chunk = $chunk->collect()->values();
            if ($reminder->canBeMuted()) {
                // Rappels coupés (EF-NOT-04) : ni dans l'application ni en push.
                $chunk = $chunk->filter(fn (User $user) => $user->reminders_enabled)->values();
            }
            $ids = $chunk->map(fn (User $user) => $notification->idFor($user));
            $done = DatabaseNotification::query()->whereKey($ids->all())->pluck('id')->flip();

            $sent += $notification->sendOnce($chunk->reject(fn (User $user, int $index) => $done->has($ids[$index])));
        }

        return $sent;
    }
}
