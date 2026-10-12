<?php

namespace App\Domain\Days;

use App\Domain\Settings\Settings;
use App\Events\CutoffExtended;
use App\Events\DayClosed;
use App\Http\Problems\ApiException;
use App\Http\Problems\ErrorCode;
use App\Models\BusinessDay;
use App\Models\Region;
use App\Models\RegionDay;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Calendrier des journées commerciales (§4, §6.1, EF-ADV-01) : ouverture
 * avec les heures limites des paramètres, une journée régionale par région
 * active, heures limites de la journée, prolongations par région (RG-A05)
 * et clôture (§4.4). Heures saisies « HH:MM » dans le fuseau métier,
 * enregistrées en UTC.
 */
final class DayCalendar
{
    public function __construct(private readonly Settings $settings) {}

    public function isWorkingDay(CarbonInterface $day): bool
    {
        $working = array_map('intval', (array) $this->settings->get('calendar.working_days'));

        return in_array((int) $day->dayOfWeek, $working, true);
    }

    /** Livraison prévue par défaut : le jour ouvré suivant (Q-07 : livraison J+1). */
    public function nextWorkingDay(CarbonInterface $day): CarbonImmutable
    {
        $next = CarbonImmutable::instance($day)->addDay();
        for ($i = 0; $i < 7 && ! $this->isWorkingDay($next); $i++) {
            $next = $next->addDay();
        }

        return $next;
    }

    /**
     * Ouvre la journée : heures limites copiées des paramètres (§4.3), une
     * journée régionale en brouillon par région active.
     *
     * @throws ApiException INVALID_TRANSITION si la journée existe déjà
     */
    public function open(CarbonImmutable $day, ?User $by): BusinessDay
    {
        try {
            return DB::transaction(function () use ($day, $by): BusinessDay {
                $times = [];
                foreach (CutoffKind::cases() as $kind) {
                    $times[$kind->column()] = BusinessClock::at($day, (string) $this->settings->get($kind->settingKey()))->utc();
                }

                $businessDay = BusinessDay::create([
                    ...$times,
                    'day' => $day->format('Y-m-d'),
                    'delivery_date' => $this->nextWorkingDay($day)->format('Y-m-d'),
                    'created_by' => $by?->id,
                ]);
                $this->syncRegions($businessDay);

                activity('days')
                    ->performedOn($businessDay)
                    ->causedBy($by)
                    ->event('opened')
                    ->withProperties(['attributes' => ['day' => $businessDay->dayKey(), ...$this->localSchedule($businessDay)]])
                    ->log(__('days.audit.opened'));

                return $businessDay;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiException(ErrorCode::InvalidTransition, __('days.already_open', ['day' => $day->format('d/m/Y')]));
        }
    }

    /** Journées régionales des régions actives absentes de la journée (région créée ou réactivée depuis). */
    public function syncRegions(BusinessDay $day): void
    {
        $existing = $day->regionDays()->pluck('region_id')->all();

        Region::query()
            ->where('is_active', true)
            ->whereNotIn('id', $existing)
            ->orderBy('id')
            ->each(fn (Region $region) => $this->createRegionDay($day, $region));
    }

    /** Journée régionale d'une région (créée au besoin, région active seulement). */
    public function regionDayFor(BusinessDay $day, Region $region): RegionDay
    {
        return $this->findRegionDay($day, $region) ?? $this->createRegionDay($day, $region);
    }

    /**
     * Heures limites et date de livraison de la journée (§4.3), « HH:MM »
     * dans le fuseau métier, strictement croissantes.
     *
     * @param  array<string, string>  $times  valeur de CutoffKind → « HH:MM »
     */
    public function updateSchedule(BusinessDay $day, array $times, ?CarbonImmutable $deliveryDate, User $by): BusinessDay
    {
        return DB::transaction(function () use ($day, $times, $deliveryDate, $by): BusinessDay {
            $day = BusinessDay::query()->lockForUpdate()->findOrFail($day->id);
            $this->assertOpen($day);

            $before = $this->localSchedule($day);
            $moments = [];
            foreach (CutoffKind::chronological() as $kind) {
                $moments[$kind->value] = isset($times[$kind->value])
                    ? BusinessClock::at($day->day, $times[$kind->value])->utc()
                    : $day->cutoff($kind)->toImmutable();
            }

            $previous = null;
            foreach (CutoffKind::chronological() as $kind) {
                if ($previous !== null && $moments[$kind->value]->lessThanOrEqualTo($previous)) {
                    throw ValidationException::withMessages(['cutoffs.'.strtolower($kind->value) => [__('days.cutoff_order')]]);
                }
                $previous = $moments[$kind->value];
            }

            $closing = $moments[CutoffKind::Closing->value];
            if ($closing->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages(['cutoffs.closing' => [__('days.closing_in_past')]]);
            }
            $lateOverride = $day->regionDays()
                ->where(fn ($q) => $q->where('distribution_cutoff_override_at', '>', $closing)->orWhere('ordering_cutoff_override_at', '>', $closing))
                ->exists();
            if ($lateOverride) {
                throw ValidationException::withMessages(['cutoffs.closing' => [__('days.override_after_closing')]]);
            }

            foreach (CutoffKind::cases() as $kind) {
                $day->setAttribute($kind->column(), $moments[$kind->value]);
            }
            if ($deliveryDate !== null) {
                $day->delivery_date = $deliveryDate;
            }
            $day->save();
            // Prolongation dépassée par la nouvelle heure de la journée : sans objet.
            foreach ([CutoffKind::Distribution, CutoffKind::Ordering] as $kind) {
                $day->regionDays()
                    ->where($kind->overrideColumn(), '<=', $moments[$kind->value])
                    ->update([$kind->overrideColumn() => null, 'updated_at' => now()]);
            }

            $after = $this->localSchedule($day);
            if ($after !== $before) {
                activity('days')
                    ->performedOn($day)
                    ->causedBy($by)
                    ->event('schedule.updated')
                    ->withProperties(['old' => array_diff_assoc($before, $after), 'attributes' => array_diff_assoc($after, $before)])
                    ->log(__('days.audit.schedule'));
            }

            return $day;
        });
    }

    /**
     * Prolonge l'heure limite de répartition ou de commande d'une région
     * (RG-A05) ; tracée, et les acteurs concernés sont prévenus.
     */
    public function extendCutoff(RegionDay $regionDay, CutoffKind $kind, string $time, User $by): RegionDay
    {
        $column = $kind->overrideColumn();
        if ($column === null) {
            throw ValidationException::withMessages(['kind' => [__('days.not_extendable')]]);
        }

        $regionDay = DB::transaction(function () use ($regionDay, $kind, $column, $time, $by): RegionDay {
            $regionDay = RegionDay::query()->with(['businessDay', 'region'])->lockForUpdate()->findOrFail($regionDay->id);
            $day = $regionDay->businessDay;
            $this->assertOpen($day);
            if ($regionDay->status === RegionDayStatus::Closed) {
                throw new ApiException(ErrorCode::DayNotOpen, __('days.region_closed', ['region' => $regionDay->region->code]));
            }

            $current = $regionDay->effectiveCutoff($kind);
            $until = BusinessClock::at($day->day, $time)->utc();
            if ($until->lessThanOrEqualTo($current)) {
                throw ValidationException::withMessages(['until' => [__('days.extension_not_later', ['current' => BusinessClock::toLocal($current)->format('H:i')])]]);
            }
            if ($until->greaterThan($day->closing_at)) {
                throw ValidationException::withMessages(['until' => [__('days.extension_after_closing', ['closing' => BusinessClock::toLocal($day->closing_at)->format('H:i')])]]);
            }

            $regionDay->setAttribute($column, $until);
            $regionDay->save();

            activity('days')
                ->performedOn($regionDay)
                ->causedBy($by)
                ->event('cutoff.extended')
                ->withProperties([
                    'day' => $day->dayKey(),
                    'region' => $regionDay->region->code,
                    'kind' => $kind->value,
                    'old' => [$column => BusinessClock::toLocal($current)->format('H:i')],
                    'attributes' => [$column => BusinessClock::toLocal($until)->format('H:i')],
                ])
                ->log(__('days.audit.extended'));

            event(new CutoffExtended($regionDay->id, $kind, $by->id));

            return $regionDay;
        });

        return $regionDay;
    }

    /**
     * Clôture la journée (§4.4) : journée et journées régionales clôturées,
     * quotas figés. Rejouable sans double effet.
     */
    public function close(BusinessDay $day): bool
    {
        return DB::transaction(function () use ($day): bool {
            $day = BusinessDay::query()->lockForUpdate()->findOrFail($day->id);
            if (! $day->isOpen()) {
                return false;
            }

            $day->status = BusinessDayStatus::Closed;
            $day->closed_at = now();
            $day->save();
            $day->regionDays()->update(['status' => RegionDayStatus::Closed->value, 'updated_at' => now()]);

            activity('days')->performedOn($day)->event('closed')->log(__('days.audit.closed'));
            event(new DayClosed($day->id));

            return true;
        });
    }

    /** Clôture les journées ouvertes dont l'heure de clôture est passée. */
    public function closeDue(?CarbonInterface $now = null): int
    {
        $closed = 0;
        BusinessDay::query()
            ->where('status', BusinessDayStatus::Open->value)
            ->where('closing_at', '<=', ($now ?? now())->toImmutable()->utc())
            ->orderBy('day')
            ->each(function (BusinessDay $day) use (&$closed): void {
                $closed += $this->close($day) ? 1 : 0;
            });

        return $closed;
    }

    public function assertOpen(BusinessDay $day): void
    {
        if (! $day->isOpen()) {
            throw new ApiException(ErrorCode::DayNotOpen, __('days.closed', ['day' => $day->day->format('d/m/Y')]));
        }
    }

    /**
     * Heures limites « HH:MM » (fuseau métier) et date de livraison, pour le journal.
     *
     * @return array<string, string|null>
     */
    private function localSchedule(BusinessDay $day): array
    {
        $schedule = [];
        foreach (CutoffKind::chronological() as $kind) {
            $schedule[$kind->column()] = BusinessClock::toLocal($day->cutoff($kind))->format('H:i');
        }
        $schedule['delivery_date'] = $day->delivery_date?->format('Y-m-d');

        return $schedule;
    }

    /**
     * Crée la journée régionale si elle n'existe pas encore. Idempotent : deux
     * écrans qui se rafraîchissent en même temps ne se gênent pas (pas d'erreur
     * de clé en double), et chacun relit la même ligne.
     */
    private function createRegionDay(BusinessDay $day, Region $region): RegionDay
    {
        // Statut relu en base : la journée a pu être clôturée depuis sa lecture.
        $open = BusinessDay::query()->whereKey($day->id)->value('status') === BusinessDayStatus::Open;
        RegionDay::query()->insertOrIgnore([
            'business_day_id' => $day->id,
            'region_id' => $region->id,
            'status' => ($open ? RegionDayStatus::QuotaDraft : RegionDayStatus::Closed)->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->findRegionDay($day, $region, lock: true) ?? throw new \LogicException('Journée régionale introuvable.');
    }

    /** Lecture verrouillante : voit une ligne validée par une autre transaction (MySQL, REPEATABLE READ). */
    private function findRegionDay(BusinessDay $day, Region $region, bool $lock = false): ?RegionDay
    {
        return RegionDay::query()
            ->where('business_day_id', $day->id)
            ->where('region_id', $region->id)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }
}
