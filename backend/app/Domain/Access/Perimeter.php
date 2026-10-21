<?php

namespace App\Domain\Access;

use App\Domain\Referentials\AssignmentKind;
use App\Domain\Referentials\ClientStatus;
use App\Models\Client;
use App\Models\RegionAssignment;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Périmètre des données d'un utilisateur (§3.3), appliqué côté serveur à
 * chaque requête (RG-S02) :
 *
 * - Admin : toutes les régions ;
 * - ADV : toutes les régions, ou celles de ses affectations ADV actives dès
 *   qu'il a été affecté à une région (RG-S05) ;
 * - Régional : régions de ses affectations actives, titulaire ou suppléant,
 *   à la date de la journée concernée ; clients actifs ou bloqués (RG-S01) ;
 * - Client : sa propre fiche.
 *
 * La région d'un client est sa région effective à la date donnée (RG-S04).
 * Les listes filtrent en SQL (clients()) ; les contrôles unitaires passent par
 * canAccessClient() / canAccessRegion().
 */
final class Perimeter
{
    /**
     * Régions accessibles le jour donné (aujourd'hui par défaut).
     *
     * @return list<int>|null null : toutes les régions
     */
    public function regionIds(User $user, ?CarbonInterface $day = null): ?array
    {
        $day ??= BusinessClock::today();

        return match ($user->appRole()) {
            Role::Admin => null,
            Role::Adv => $this->advRegionIds($user, $day),
            Role::Regional => $this->assignedRegionIds($user, [AssignmentKind::Titular, AssignmentKind::Deputy], $day),
            Role::Client => $user->client === null ? [] : [$user->client->effectiveRegionId($day)],
            null => [],
        };
    }

    public function coversAllRegions(User $user, ?CarbonInterface $day = null): bool
    {
        return $this->regionIds($user, $day) === null;
    }

    public function canAccessRegion(User $user, int $regionId, ?CarbonInterface $day = null): bool
    {
        if ($user->appRole() === Role::Client) {
            return false;
        }

        $regionIds = $this->regionIds($user, $day);

        return $regionIds === null || in_array($regionId, $regionIds, true);
    }

    /**
     * Clients visibles par l'utilisateur, en requête filtrable et paginable.
     *
     * @return Builder<Client>
     */
    public function clients(User $user, ?CarbonInterface $day = null): Builder
    {
        $day ??= BusinessClock::today();
        $query = Client::query();
        $role = $user->appRole();

        if ($role === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($role === Role::Client) {
            return $query->whereKey($user->client_id ?? 0);
        }

        $regionIds = $this->regionIds($user, $day);
        if ($regionIds !== null) {
            $query->inEffectiveRegions($regionIds, $day);
        }

        if ($role === Role::Regional) {
            $query->whereIn('status', [ClientStatus::Active->value, ClientStatus::Blocked->value]);
        }

        return $query;
    }

    /**
     * Commandes visibles par l'utilisateur, périmètre jugé **à la journée de
     * chaque commande** (I-12, RG-S05) et sur la région figée à l'envoi
     * (RG-S04) : file ADV sur plusieurs journées, résolution des commandes
     * d'un traitement en masse. Sans effet pour l'Admin et pour un ADV jamais
     * affecté ; sinon une affectation active ce jour-là (ADV : affectation
     * ADV ; régional : titulaire ou suppléant) sur la région de la commande,
     * même règle que regionIds(). Client : ses commandes.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query  requête sur `orders`
     * @return Builder<TModel>
     */
    public function constrainOrders(Builder $query, User $user): Builder
    {
        $table = $query->getModel()->getTable();
        $kinds = match ($user->appRole()) {
            Role::Admin => null,
            Role::Adv => $this->isRestrictedAdv($user) ? [AssignmentKind::Adv] : null,
            Role::Regional => [AssignmentKind::Titular, AssignmentKind::Deputy],
            Role::Client => [],
            null => [],
        };
        if ($kinds === null) {
            return $query;
        }
        if ($kinds === []) {
            return $user->appRole() === Role::Client && $user->client_id !== null
                ? $query->where("{$table}.client_id", $user->client_id)
                : $query->whereRaw('1 = 0');
        }

        return $query->whereExists(fn ($exists) => $exists
            ->selectRaw('1')
            ->from('region_assignments')
            ->join('business_days', 'business_days.id', '=', "{$table}.business_day_id")
            ->whereColumn('region_assignments.region_id', "{$table}.region_id")
            ->where('region_assignments.user_id', $user->id)
            ->whereIn('region_assignments.kind', array_map(fn (AssignmentKind $kind) => $kind->value, $kinds))
            ->whereNull('region_assignments.revoked_at')
            ->whereColumn('region_assignments.starts_on', '<=', 'business_days.day')
            ->where(fn ($q) => $q->whereNull('region_assignments.ends_on')->orWhereColumn('region_assignments.ends_on', '>=', 'business_days.day')));
    }

    public function canAccessClient(User $user, Client $client, ?CarbonInterface $day = null): bool
    {
        $day ??= BusinessClock::today();
        $role = $user->appRole();

        return match ($role) {
            null => false,
            Role::Client => $user->client_id !== null && $user->client_id === $client->id,
            default => $this->regionAllows($user, $client->effectiveRegionId($day), $day)
                && ($role !== Role::Regional || $client->status !== ClientStatus::Inactive),
        };
    }

    private function regionAllows(User $user, int $regionId, CarbonInterface $day): bool
    {
        $regionIds = $this->regionIds($user, $day);

        return $regionIds === null || in_array($regionId, $regionIds, true);
    }

    /**
     * RG-S05 : toutes les régions tant que l'ADV n'a jamais été affecté à une
     * région. Dès sa première affectation ADV (en cours, à venir, terminée ou
     * révoquée), il est limité à ses affectations actives du jour, éventuellement
     * aucune : mettre fin à une affectation retire une région, jamais n'élargit
     * le périmètre à toutes les régions.
     *
     * @return list<int>|null
     */
    private function advRegionIds(User $user, CarbonInterface $day): ?array
    {
        return $this->isRestrictedAdv($user) ? $this->assignedRegionIds($user, [AssignmentKind::Adv], $day) : null;
    }

    /** ADV affecté au moins une fois à une région (RG-S05), quelle que soit la date. */
    private function isRestrictedAdv(User $user): bool
    {
        return RegionAssignment::query()
            ->where('user_id', $user->id)
            ->where('kind', AssignmentKind::Adv->value)
            ->exists();
    }

    /**
     * @param  list<AssignmentKind>  $kinds
     * @return list<int>
     */
    private function assignedRegionIds(User $user, array $kinds, CarbonInterface $day): array
    {
        return RegionAssignment::query()
            ->where('user_id', $user->getKey())
            ->whereIn('kind', array_map(fn (AssignmentKind $k) => $k->value, $kinds))
            ->activeOn($day)
            ->distinct()
            ->orderBy('region_id')
            ->pluck('region_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
