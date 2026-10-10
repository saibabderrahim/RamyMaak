<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Perimeter;
use App\Domain\Referentials\ClientStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Regions\StoreRegionRequest;
use App\Http\Requests\Regions\UpdateRegionRequest;
use App\Http\Resources\RegionResource;
use App\Models\Client;
use App\Models\Region;
use App\Models\User;
use App\Support\BusinessClock;
use App\Support\FilterValue;
use App\Support\Pagination;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Régions commerciales (EF-ADM-03) : lecture dans le périmètre (RG-S02) par
 * GET /regions et GET /admin/regions, création et modification par l'Admin.
 */
class RegionController extends Controller
{
    public function __construct(private readonly Perimeter $perimeter) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Region::class);

        /** @var User $user */
        $user = $request->user();
        $query = $this->regions(BusinessClock::today());

        // Périmètre filtré en SQL : le régional ne voit que ses régions.
        $regionIds = $this->perimeter->regionIds($user);
        if ($regionIds !== null) {
            $query->whereIn('regions.id', $regionIds);
        }
        if (! $user->can('viewInactive', Region::class)) {
            $query->where('regions.is_active', true);
        }

        $regions = QueryBuilder::for($query)
            ->allowedFilters(
                AllowedFilter::callback('search', function (Builder $query, mixed $value): void {
                    $term = '%'.str_replace(['%', '_'], ['\%', '\_'], FilterValue::text($value, 'search')).'%';
                    $query->where(fn (Builder $q) => $q->where('regions.code', 'like', $term)->orWhere('regions.name', 'like', $term));
                }),
                AllowedFilter::exact('is_active', 'regions.is_active'),
            )
            ->allowedSorts('code', 'name')
            ->defaultSort('code')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return RegionResource::collection($regions);
    }

    public function show(Region $region): RegionResource
    {
        Gate::authorize('view', $region);

        return $this->resource($region);
    }

    public function store(StoreRegionRequest $request): JsonResponse
    {
        $region = Region::create($request->validated());

        return $this->resource($region)->response()->setStatusCode(201);
    }

    public function update(UpdateRegionRequest $request, Region $region): RegionResource
    {
        // Le code n'est pas modifiable : seuls ces champs sont repris.
        $region->update($request->safe()->only(['name', 'depot_id', 'is_active']));

        return $this->resource($region);
    }

    private function resource(Region $region): RegionResource
    {
        return new RegionResource($this->regions(BusinessClock::today())->findOrFail($region->id));
    }

    /**
     * Régions prêtes pour RegionResource, sans requête par ligne : relations
     * chargées et clients comptés par sous-requête.
     *
     * @return Builder<Region>
     */
    private function regions(CarbonInterface $today): Builder
    {
        return Region::query()
            ->select('regions.*')
            ->selectSub($this->clientsCount($today), 'clients_count')
            ->with(RegionResource::relations($today));
    }

    /**
     * Clients non inactifs dont la région effective le jour donné est la
     * région de la ligne (RG-S04) : même règle que
     * Client::scopeInEffectiveRegions(), corrélée à `regions.id`.
     *
     * @return Builder<Client>
     */
    private function clientsCount(CarbonInterface $day): Builder
    {
        $date = $day->format('Y-m-d');

        return Client::query()
            ->selectRaw('count(*)')
            ->where('clients.status', '!=', ClientStatus::Inactive->value)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $current) => $current
                    ->whereColumn('clients.region_id', 'regions.id')
                    ->where(fn (Builder $p) => $p->whereNull('clients.region_change_on')->orWhere('clients.region_change_on', '>', $date)))
                ->orWhere(fn (Builder $next) => $next
                    ->whereColumn('clients.next_region_id', 'regions.id')
                    ->where('clients.region_change_on', '<=', $date)));
    }
}
