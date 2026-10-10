<?php

namespace App\Http\Resources;

use App\Domain\Access\Perimeter;
use App\Domain\Days\CutoffKind;
use App\Domain\Days\DayCalendar;
use App\Models\BusinessDay;
use App\Models\RegionDay;
use App\Models\User;
use App\Support\BusinessClock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Journée (EF-ADV-01) : heures limites en heure d'Alger (« HH:MM ») et en
 * instants UTC, régions actives du périmètre du lecteur, triées par code.
 *
 * @mixin BusinessDay
 */
class DayResource extends JsonResource
{
    /**
     * Journées régionales visibles par l'utilisateur : régions actives de
     * son périmètre ce jour-là (RG-S05), avec le nombre de brouillons en
     * attente. Une journée ouverte reçoit d'abord les régions créées depuis
     * son ouverture (DayCalendar::syncRegions).
     *
     * @return Collection<int, RegionDay>
     */
    public static function visibleRegionDays(BusinessDay $day, User $user): Collection
    {
        if ($day->isOpen()) {
            app(DayCalendar::class)->syncRegions($day);
        }
        $regionIds = app(Perimeter::class)->regionIds($user, $day->day);

        return $day->regionDays()
            ->with(['region', 'businessDay'])
            ->withCount(['quotas as pending_drafts_count' => fn ($query) => $query->whereNotNull('draft_quantity')])
            ->whereHas('region', fn ($query) => $query
                ->where('is_active', true)
                ->when($regionIds !== null, fn ($query) => $query->whereKey($regionIds)))
            ->get()
            ->sortBy(fn (RegionDay $regionDay) => $regionDay->region->code)
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $cutoffs = [];
        $cutoffsAt = [];
        foreach (CutoffKind::chronological() as $kind) {
            $key = strtolower($kind->value);
            $cutoffs[$key] = BusinessClock::toLocal($this->cutoff($kind))->format('H:i');
            $cutoffsAt[$key] = $this->cutoff($kind)->toIso8601String();
        }

        return [
            'day' => $this->dayKey(),
            'weekday' => $this->day->dayOfWeek,
            'is_working_day' => app(DayCalendar::class)->isWorkingDay($this->day),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'delivery_date' => $this->delivery_date?->format('Y-m-d'),
            'cutoffs' => $cutoffs,
            'cutoffs_at' => $cutoffsAt,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'regions' => RegionDayResource::collection(self::visibleRegionDays($this->resource, $request->user())),
            'created_by' => $this->createdBy === null ? null : [
                'id' => $this->createdBy->id,
                'username' => $this->createdBy->username,
                'name' => $this->createdBy->name,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
