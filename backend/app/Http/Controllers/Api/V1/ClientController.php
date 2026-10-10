<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Perimeter;
use App\Domain\Referentials\ClientRegistry;
use App\Domain\Referentials\ClientStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\StoreClientRequest;
use App\Http\Requests\Clients\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\User;
use App\Support\BusinessClock;
use App\Support\FilterValue;
use App\Support\Pagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Référentiel clients (EF-CLI-01, EF-CLI-03, EF-CLI-04). Lectures restreintes
 * au périmètre (RG-S02) ; écritures par ClientRegistry (code immuable,
 * région à J+1, statut motivé).
 */
class ClientController extends Controller
{
    public function __construct(
        private readonly Perimeter $perimeter,
        private readonly ClientRegistry $registry,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Client::class);

        /** @var User $user */
        $user = $request->user();
        $today = BusinessClock::today();

        $clients = QueryBuilder::for($this->perimeter->clients($user, $today)->with(ClientResource::relations($user)))
            ->allowedFilters(
                // Une recherche peut contenir une virgule : pas de découpage en liste.
                AllowedFilter::callback('search', fn (Builder $query, mixed $value) => $this->search($query, $value))
                    ->delimiter(''),
                // Région effective aujourd'hui (RG-S04).
                AllowedFilter::callback('region_id', function (Builder $query, mixed $value) use ($today): void {
                    $query->inEffectiveRegions($this->identifiers($value, 'region_id'), $today);
                }),
                AllowedFilter::callback('category_id', function (Builder $query, mixed $value): void {
                    $query->whereIn('category_id', $this->identifiers($value, 'category_id'));
                }),
                AllowedFilter::callback('status', function (Builder $query, mixed $value): void {
                    $statuses = collect(FilterValue::scalars($value, 'status'))->map(fn (string $s) => ClientStatus::tryFrom(mb_strtoupper($s)));
                    if ($statuses->contains(null)) {
                        throw ValidationException::withMessages(['filter.status' => [__('clients.unknown_status')]]);
                    }
                    $query->whereIn('status', $statuses->map->value->all());
                }),
            )
            ->allowedSorts('code', 'name', 'updated_at')
            ->defaultSort('code')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return ClientResource::collection($clients);
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        $client = $this->registry->create($request->validated(), $request->user());

        return (new ClientResource($client->load(ClientResource::relations($request->user()))))
            ->response()
            ->setStatusCode(201);
    }

    /** Fiche dans le périmètre ; un compte client lit la sienne (404 sinon). */
    public function show(Request $request, Client $client): ClientResource
    {
        Gate::authorize('view', $client);

        return new ClientResource($client->load(ClientResource::relations($request->user())));
    }

    /**
     * Modification partielle : `region_id` programme le changement pour la
     * journée suivante (RG-S04) ; `status` passe par ClientRegistry, qui
     * désactive les comptes d'un client inactif (§3.5).
     */
    public function update(UpdateClientRequest $request, Client $client): ClientResource
    {
        $client = $this->registry->update($client, $request->safe()->except('code'), $request->user());

        return new ClientResource($client->load(ClientResource::relations($request->user())));
    }

    /** Code, raison sociale, enseigne ou téléphone (EF-CLI-03 : retrouver et appeler un client). */
    private function search(Builder $query, mixed $value): void
    {
        $value = FilterValue::text($value, 'search');
        if ($value === '') {
            return;
        }

        // Échappement explicite (« ! ») : les codes clients contiennent des « _ »,
        // et MySQL comme SQLite doivent les lire littéralement.
        $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
        // Numéro saisi avec ou sans espaces : « 0550 12 34 56 » trouve « 0550123456 ».
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        $query->where(function (Builder $q) use ($term, $digits): void {
            $grammar = $q->getQuery()->getGrammar();

            foreach (['code', 'name', 'trade_name', 'phone', 'phone2'] as $column) {
                $q->orWhereRaw($grammar->wrap($column)." LIKE ? ESCAPE '!'", [$term]);
            }

            if (strlen($digits) >= 3) {
                foreach (['phone', 'phone2'] as $column) {
                    $q->orWhereRaw('REPLACE('.$grammar->wrap($column).", ' ', '') LIKE ?", ["%{$digits}%"]);
                }
            }
        });
    }

    /**
     * @return list<int>
     */
    private function identifiers(mixed $value, string $filter): array
    {
        $values = (array) $value;
        foreach ($values as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                throw ValidationException::withMessages(["filter.{$filter}" => [__('clients.invalid_identifier')]]);
            }
        }

        return array_values(array_map('intval', $values));
    }
}
