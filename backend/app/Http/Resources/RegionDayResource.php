<?php

namespace App\Http\Resources;

use App\Domain\Days\CutoffKind;
use App\Models\RegionDay;
use App\Support\BusinessClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une région pour une journée (§6.1) : statut, version des quotas, heures
 * limites applicables, prolongation comprise (RG-A05). `pending_drafts` :
 * cellules en brouillon non publié (attribut `pending_drafts_count`, chargé
 * par DayResource::visibleRegionDays). `distribution_version` : 0 tant que
 * la répartition n'a pas été envoyée aux clients (lot 4).
 *
 * @mixin RegionDay
 */
class RegionDayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $cutoffs = [];
        $extended = [];
        foreach ([CutoffKind::Distribution, CutoffKind::Ordering] as $kind) {
            $key = strtolower($kind->value);
            $cutoffs[$key] = BusinessClock::toLocal($this->effectiveCutoff($kind))->format('H:i');
            $extended[$key] = $this->isExtended($kind);
        }

        return [
            'region' => [
                'id' => $this->region->id,
                'code' => $this->region->code,
                'name' => $this->region->name,
            ],
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'quota_version' => $this->quota_version,
            'quota_published_at' => $this->quota_published_at?->toIso8601String(),
            'quota_last_published_at' => $this->quota_last_published_at?->toIso8601String(),
            'distribution_version' => $this->distribution_version,
            'distribution_published_at' => $this->distribution_published_at?->toIso8601String(),
            'pending_drafts' => (int) ($this->pending_drafts_count ?? $this->quotas()->pendingOn($this->businessDay->day)->count()),
            'cutoffs' => $cutoffs,
            'extended' => $extended,
        ];
    }
}
