<?php

namespace App\Domain\Days;

use App\Domain\Referentials\AssignmentKind;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Personnes à prévenir pour une région un jour donné (§7.8) : titulaire et
 * suppléants dont l'affectation est active ce jour-là (§3.4), comptes actifs.
 */
final class RegionStaff
{
    /**
     * @return Collection<int, User>
     */
    public function regionals(int $regionId, CarbonInterface $day): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('regionAssignments', fn (Builder $q) => $q
                ->where('region_id', $regionId)
                ->whereIn('kind', [AssignmentKind::Titular->value, AssignmentKind::Deputy->value])
                ->activeOn($day))
            ->orderBy('id')
            ->get();
    }
}
