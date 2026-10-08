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
 * - ADV : toutes les régions, ou celles de ses affectations ADV (RG-S05) ;
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
            // Sans affectation ADV, l'ADV voit toutes les régions (RG-S05).
            Role::Adv => $this->assignedRegionIds($user, [AssignmentKind::Adv], $day) ?: null,
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
