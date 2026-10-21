<?php

namespace App\Http\Resources;

use App\Domain\Access\Perimeter;
use App\Domain\Days\CutoffKind;
use App\Domain\Days\DayCalendar;
use App\Models\BusinessDay;
use App\Models\RegionDay;
use App\Models\RegionQuota;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Quotas du jour vus par le régional (§7.6, EF-REG-01) : pour chaque région
 * de son périmètre à cette date (titulaire ou suppléant, §3.4), statut,
 * heures limites applicables (RG-A05) et quotas **publiés** > 0 ; aucun
 * brouillon n'est jamais exposé (RG-Q03). Une région non encore publiée a
 * `quotas: []`. Journée non ouverte : `opened: false`.
 *
 * @mixin BusinessDay
 */
class RegionalQuotasResource extends JsonResource
{
    /**
     * @param  Collection<int, RegionDay>  $regionDays  régions du périmètre, par code
     * @param  Collection<int, Collection<int, object>>  $quotas  quotas publiés > 0 par journée régionale, dans l'ordre d'affichage
     */
    public function __construct(
        ?BusinessDay $day,
        private readonly CarbonImmutable $date,
        private readonly Collection $regionDays,
        private readonly Collection $quotas,
    ) {
        parent::__construct($day);
    }

    public static function for(CarbonImmutable $date, User $user): self
    {
        $day = BusinessDay::forDay($date->format('Y-m-d'));
        if ($day === null) {
            return new self(null, $date, collect(), collect());
        }

        // Une région créée depuis l'ouverture apparaît en attente des quotas.
        if ($day->isOpen()) {
            app(DayCalendar::class)->syncRegions($day);
        }
        $regionIds = app(Perimeter::class)->regionIds($user, $date);

        $regionDays = $day->regionDays()
            ->with(['region', 'businessDay'])
            ->whereHas('region', fn ($query) => $query
                ->where('is_active', true)
                ->when($regionIds !== null, fn ($query) => $query->whereKey($regionIds)))
            ->get()
            ->sortBy(fn (RegionDay $regionDay) => $regionDay->region->code)
            ->values();

        $published = $regionDays->filter(fn (RegionDay $regionDay) => $regionDay->isQuotaPublished())->pluck('id')->all();
        $quotas = RegionQuota::query()
            ->join('products', 'products.id', '=', 'region_quotas.product_id')
            ->join('product_families', 'product_families.id', '=', 'products.family_id')
            ->whereIn('region_quotas.region_day_id', $published)
            ->where('region_quotas.quantity', '>', 0)
            // Même ordre que les lignes de la matrice ADV.
            ->orderBy('product_families.sort_order')
            ->orderBy('product_families.code')
            ->orderBy('products.sort_order')
            ->orderBy('products.sku')
            ->toBase()
            ->get([
                'region_quotas.region_day_id', 'region_quotas.quantity', 'region_quotas.validated_qty',
                'products.id as product_id', 'products.sku', 'products.name', 'products.sales_unit_label',
                'product_families.id as family_id', 'product_families.code as family_code', 'product_families.name as family_name',
            ])
            ->groupBy('region_day_id');

        return new self($day, $date, $regionDays, $quotas);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->resource === null) {
            return ['day' => $this->date->format('Y-m-d'), 'opened' => false, 'status' => null, 'cutoffs' => null, 'regions' => []];
        }

        $cutoffs = [];
        foreach (CutoffKind::chronological() as $kind) {
            $cutoffs[strtolower($kind->value)] = BusinessClock::toLocal($this->cutoff($kind))->format('H:i');
        }

        return [
            'day' => $this->dayKey(),
            'opened' => true,
            'status' => $this->status->value,
            'cutoffs' => $cutoffs,
            'regions' => $this->regionDays->map(fn (RegionDay $regionDay) => $this->region($regionDay))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function region(RegionDay $regionDay): array
    {
        $cutoffs = [];
        foreach ([CutoffKind::Distribution, CutoffKind::Ordering] as $kind) {
            $cutoffs[strtolower($kind->value)] = BusinessClock::toLocal($regionDay->effectiveCutoff($kind))->format('H:i');
        }

        return [
            'region' => [
                'id' => $regionDay->region->id,
                'code' => $regionDay->region->code,
                'name' => $regionDay->region->name,
            ],
            'status' => $regionDay->status->value,
            'quota_version' => $regionDay->quota_version,
            'quota_last_published_at' => $regionDay->quota_last_published_at?->toIso8601String(),
            'distribution_version' => $regionDay->distribution_version,
            'cutoffs' => $cutoffs,
            'quotas' => $this->quotas->get($regionDay->id, collect())->map(fn (object $row) => [
                'product' => [
                    'id' => (int) $row->product_id,
                    'sku' => $row->sku,
                    'name' => $row->name,
                    'family' => ['id' => (int) $row->family_id, 'code' => $row->family_code, 'name' => $row->family_name],
                    'sales_unit_label' => $row->sales_unit_label,
                ],
                'quantity' => (int) $row->quantity,
                'validated' => (int) $row->validated_qty,
            ])->values()->all(),
        ];
    }
}
