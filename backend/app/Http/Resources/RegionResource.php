<?php

namespace App\Http\Resources;

use App\Domain\Referentials\AssignmentKind;
use App\Domain\Referentials\ClientStatus;
use App\Models\Client;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Support\BusinessClock;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Région (EF-ADM-03) : titulaire actif aujourd'hui (H-02), suppléants non
 * révoqués en cours ou à venir (§3.4), nombre de clients non inactifs dont la
 * région effective aujourd'hui est celle-ci (RG-S04).
 *
 * Les listes chargent relations() et l'attribut `clients_count` en une passe
 * (RegionController) ; à défaut, la ressource les calcule elle-même.
 *
 * @mixin Region
 */
class RegionResource extends JsonResource
{
    /**
     * Relations utiles à la ressource : affectations de titulaire et de
     * suppléant encore en cours ou à venir le jour donné.
     *
     * @return array<int|string, mixed>
     */
    public static function relations(CarbonInterface $today): array
    {
        return [
            'depot',
            'assignments' => fn (HasMany $query) => $query
                ->whereIn('kind', [AssignmentKind::Titular->value, AssignmentKind::Deputy->value])
                ->currentOrUpcoming($today)
                ->with('user')
                ->orderBy('starts_on')
                ->orderBy('id'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $today = BusinessClock::today();
        $this->resource->loadMissing(self::relations($today));

        // Filtrage répété ici : la relation a pu être chargée sans contrainte.
        $assignments = $this->assignments
            ->filter(fn (RegionAssignment $a) => $a->revoked_at === null
                && ($a->ends_on === null || $a->ends_on->format('Y-m-d') >= $today->format('Y-m-d')))
            ->sortBy([['starts_on', 'asc'], ['id', 'asc']]);

        $titular = $assignments->first(
            fn (RegionAssignment $a) => $a->kind === AssignmentKind::Titular && $a->isActiveOn($today),
        );
        $deputies = $assignments->filter(fn (RegionAssignment $a) => $a->kind === AssignmentKind::Deputy)->values();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'depot' => $this->depot === null ? null : [
                'id' => $this->depot->id,
                'code' => $this->depot->code,
                'name' => $this->depot->name,
            ],
            'titular' => $titular === null ? null : new AssignmentResource($titular),
            'deputies' => AssignmentResource::collection($deputies),
            'clients_count' => (int) ($this->resource->getAttribute('clients_count') ?? $this->countClients($today)),
        ];
    }

    private function countClients(CarbonInterface $today): int
    {
        return Client::query()
            ->where('status', '!=', ClientStatus::Inactive->value)
            ->inEffectiveRegions([$this->id], $today)
            ->count();
    }
}
