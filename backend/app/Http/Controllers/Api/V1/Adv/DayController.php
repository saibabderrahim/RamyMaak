<?php

namespace App\Http\Controllers\Api\V1\Adv;

use App\Domain\Days\DayCalendar;
use App\Domain\Settings\Settings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Days\ExtendCutoffRequest;
use App\Http\Requests\Days\ListDaysRequest;
use App\Http\Requests\Days\OpenDayRequest;
use App\Http\Requests\Days\UpdateDayRequest;
use App\Http\Resources\DayResource;
use App\Http\Resources\RegionDayResource;
use App\Models\BusinessDay;
use App\Models\Region;
use App\Models\RegionDay;
use App\Support\BusinessClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Calendrier des journées (EF-ADV-01, §4.2 à §4.4) : journées ouvertes d'un
 * intervalle, ouverture, heures limites et date de livraison, prolongation
 * par région (RG-A05). Les règles sont portées par DayCalendar ; les régions
 * de chaque journée sont celles du périmètre du lecteur (RG-S05).
 */
class DayController extends Controller
{
    public function __construct(private readonly DayCalendar $calendar) {}

    /** Journées ouvertes (en cours ou clôturées) de l'intervalle, par date. */
    public function index(ListDaysRequest $request, Settings $settings): JsonResponse
    {
        $from = $request->from();
        $to = $request->to();

        $days = BusinessDay::query()
            ->with('createdBy')
            ->whereBetween('day', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->orderBy('day')
            ->get();

        $workingDays = collect((array) $settings->get('calendar.working_days'))
            ->map(fn (mixed $weekday) => (int) $weekday)
            ->sort()
            ->values()
            ->all();

        return response()->json([
            'data' => DayResource::collection($days),
            'meta' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'today' => BusinessClock::today()->format('Y-m-d'),
                'working_days' => $workingDays,
            ],
        ]);
    }

    public function store(OpenDayRequest $request): JsonResponse
    {
        $day = $this->calendar->open($request->day(), $request->user());

        return (new DayResource($day->load('createdBy')))->response()->setStatusCode(201);
    }

    public function show(BusinessDay $businessDay): DayResource
    {
        Gate::authorize('view', $businessDay);

        return new DayResource($businessDay->load('createdBy'));
    }

    /** Heures limites fournies et date de livraison (§4.3) ; journée clôturée → 409 DAY_NOT_OPEN. */
    public function update(UpdateDayRequest $request, BusinessDay $businessDay): DayResource
    {
        $day = $this->calendar->updateSchedule($businessDay, $request->times(), $request->deliveryDate(), $request->user());

        return new DayResource($day->load('createdBy'));
    }

    /**
     * Prolongation pour une région (RG-A05) : journée régionale créée au
     * besoin (région activée depuis l'ouverture), heure contrôlée et tracée
     * par DayCalendar::extendCutoff, qui prévient les acteurs (CutoffExtended).
     */
    public function extendCutoff(ExtendCutoffRequest $request, BusinessDay $businessDay, Region $region): RegionDayResource
    {
        // Journée clôturée : 409 sans créer de journée régionale.
        $this->calendar->assertOpen($businessDay);

        $regionDay = $this->calendar->extendCutoff(
            $this->calendar->regionDayFor($businessDay, $region),
            $request->kind(),
            $request->until(),
            $request->user(),
        );

        return new RegionDayResource($this->refreshed($regionDay));
    }

    /** Journée régionale relue, avec le nombre de brouillons en attente. */
    private function refreshed(RegionDay $regionDay): RegionDay
    {
        $day = $regionDay->businessDay->day;

        return RegionDay::query()
            ->with(['region', 'businessDay'])
            ->withCount(['quotas as pending_drafts_count' => fn ($query) => $query->pendingOn($day)])
            ->findOrFail($regionDay->id);
    }
}
