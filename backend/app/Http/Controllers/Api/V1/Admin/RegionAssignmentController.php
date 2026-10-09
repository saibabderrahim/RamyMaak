<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Referentials\AssignmentKind;
use App\Http\Controllers\Controller;
use App\Http\Problems\ApiException;
use App\Http\Problems\ErrorCode;
use App\Http\Requests\Regions\StoreAssignmentRequest;
use App\Http\Resources\AssignmentResource;
use App\Models\Region;
use App\Models\RegionAssignment;
use App\Models\User;
use App\Support\BusinessClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Affectations aux régions : titulaire, suppléants, ADV (§3.3, §3.4,
 * RG-S05). Une fin d'affectation est une révocation datée, effective
 * aussitôt (RG-S03) ; l'historique est conservé.
 */
class RegionAssignmentController extends Controller
{
    public function index(Region $region): JsonResponse
    {
        Gate::authorize('view', $region);

        $assignments = QueryBuilder::for(
            RegionAssignment::query()
                ->where('region_id', $region->id)
                ->with(['user', 'revokedBy'])
                ->orderByDesc('starts_on')
                ->orderByDesc('id'),
        )
            ->allowedFilters(AllowedFilter::callback('active', function (Builder $query, mixed $value): void {
                $active = is_array($value) ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($active === null) {
                    throw ValidationException::withMessages(['filter.active' => [__('regions.active_filter')]]);
                }

                $today = BusinessClock::today();
                $active
                    ? $query->activeOn($today)
                    : $query->whereNot(fn (Builder $q) => $q->activeOn($today));
            }))
            ->get();

        return response()->json(['data' => AssignmentResource::collection($assignments)]);
    }

    public function store(StoreAssignmentRequest $request, Region $region): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $kind = $request->kind();
        $userId = $request->integer('user_id');
        $startsOn = $request->string('starts_on')->value();
        $endsOn = $request->filled('ends_on') ? $request->string('ends_on')->value() : null;

        $assignment = DB::transaction(function () use ($request, $region, $actor, $kind, $userId, $startsOn, $endsOn): RegionAssignment {
            // Verrou sur la région : deux créations simultanées ne peuvent
            // pas installer deux titulaires (H-02).
            Region::query()->whereKey($region->id)->lockForUpdate()->first();

            // Affectations non révoquées dont la période chevauche la nouvelle
            // (bornes incluses, fin facultative).
            $overlapping = fn (): Builder => RegionAssignment::query()
                ->where('region_id', $region->id)
                ->whereNull('revoked_at')
                ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $startsOn))
                ->when($endsOn !== null, fn (Builder $q) => $q->where('starts_on', '<=', $endsOn));

            if ($overlapping()->where('user_id', $userId)->where('kind', $kind->value)->exists()) {
                throw ValidationException::withMessages([
                    'user_id' => [__('regions.duplicate', ['kind' => $kind->label()])],
                ]);
            }

            // Un seul titulaire à la fois (H-02) : le remplacement explicite
            // met fin à l'affectation du titulaire en place.
            if ($kind === AssignmentKind::Titular) {
                $titulars = $overlapping()
                    ->where('kind', AssignmentKind::Titular->value)
                    ->with('user')
                    ->orderBy('starts_on')
                    ->get();

                if ($titulars->isNotEmpty() && ! $request->boolean('replace')) {
                    throw ValidationException::withMessages([
                        'user_id' => [trans_choice('regions.titular_taken', $titulars->count(), [
                            'name' => $titulars->pluck('user.name')->join(', '),
                        ])],
                    ]);
                }

                $titulars->each(fn (RegionAssignment $previous) => $this->revoke($previous, $actor));
            }

            return RegionAssignment::create([
                'region_id' => $region->id,
                'user_id' => $userId,
                'kind' => $kind,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'created_by' => $actor->id,
            ]);
        });

        return (new AssignmentResource($assignment->refresh()->load(['user', 'revokedBy'])))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, RegionAssignment $assignment): AssignmentResource
    {
        Gate::authorize('update', $assignment->region);

        /** @var User $actor */
        $actor = $request->user();

        $revoked = DB::transaction(function () use ($assignment, $actor): RegionAssignment {
            $locked = RegionAssignment::query()->lockForUpdate()->findOrFail($assignment->id);

            if ($locked->revoked_at !== null) {
                throw new ApiException(ErrorCode::InvalidTransition, __('regions.already_revoked'));
            }

            $this->revoke($locked, $actor);

            return $locked;
        });

        return new AssignmentResource($revoked->load(['user', 'revokedBy']));
    }

    /**
     * Fin d'affectation immédiate (RG-S03) ; revoked_at et revoked_by sont
     * journalisés par le modèle (journal `referentials`).
     */
    private function revoke(RegionAssignment $assignment, User $by): void
    {
        $assignment->forceFill(['revoked_at' => now(), 'revoked_by' => $by->id])->save();
    }
}
