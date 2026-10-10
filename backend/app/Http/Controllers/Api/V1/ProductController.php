<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Products\StoreProductRequest;
use App\Http\Requests\Products\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\User;
use App\Policies\ProductPolicy;
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
 * Catalogue produits (EF-PRD-01, EF-PRD-05) : lecture avec product.view
 * (un compte client ne voit que les produits actifs), création et
 * modification avec product.manage. Chaque fiche porte le prix en vigueur
 * aujourd'hui et le prochain prix programmé (RG-P01).
 */
class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Product::class);

        /** @var User $user */
        $user = $request->user();
        $activeOnly = ProductPolicy::seesActiveCatalogOnly($user);

        $filters = [
            // Une recherche peut contenir une virgule : pas de découpage en liste.
            AllowedFilter::callback('search', fn (Builder $query, mixed $value) => $this->search($query, $value))
                ->delimiter(''),
            // Une famille englobe ses sous-familles (gammes).
            AllowedFilter::callback('family_id', function (Builder $query, mixed $value): void {
                $query->whereIn('family_id', $this->withDescendants($this->identifiers($value, 'family_id')));
            }),
        ];

        // Le statut ne se filtre que pour le personnel : un client ne reçoit que les actifs.
        if (! $activeOnly) {
            $filters[] = AllowedFilter::callback('is_active', function (Builder $query, mixed $value): void {
                $query->where('is_active', $this->flag($value, 'is_active'));
            });
        }

        $products = QueryBuilder::for(
            ProductResource::prepare(Product::query())->when($activeOnly, fn (Builder $q) => $q->where('is_active', true)),
        )
            ->allowedFilters(...$filters)
            ->allowedSorts('sort_order', 'name', 'sku')
            ->defaultSort('sort_order')
            // Départage stable des ex æquo d'une page à l'autre.
            ->orderBy('products.id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return ProductResource::collection($products);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create($request->validated());

        return (new ProductResource($this->reload($product)))
            ->response()
            ->setStatusCode(201);
    }

    /** Fiche ; un produit inactif n'existe pas pour un compte client (404). */
    public function show(Product $product): ProductResource
    {
        Gate::authorize('view', $product);

        return new ProductResource($this->reload($product));
    }

    /** Modification partielle ; le code article ne change pas (clé ERP). */
    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $product->fill($request->safe()->except('sku'))->save();

        return new ProductResource($this->reload($product));
    }

    private function reload(Product $product): Product
    {
        return ProductResource::prepare(Product::query())->whereKey($product->getKey())->firstOrFail();
    }

    /** Code article, désignation ou désignation courte. */
    private function search(Builder $query, mixed $value): void
    {
        $value = FilterValue::text($value, 'search');
        if ($value === '') {
            return;
        }

        // Échappement explicite (« ! ») : les codes article contiennent des « _ »,
        // et MySQL comme SQLite doivent les lire littéralement.
        $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';

        $query->where(function (Builder $q) use ($term): void {
            $grammar = $q->getQuery()->getGrammar();

            foreach (['sku', 'name', 'short_name'] as $column) {
                $q->orWhereRaw($grammar->wrap('products.'.$column)." LIKE ? ESCAPE '!'", [$term]);
            }
        });
    }

    /**
     * Familles demandées et toutes leurs descendantes.
     *
     * @param  list<int>  $familyIds
     * @return list<int>
     */
    private function withDescendants(array $familyIds): array
    {
        $children = ProductFamily::query()
            ->whereNotNull('parent_id')
            ->get(['id', 'parent_id'])
            ->groupBy('parent_id')
            ->map(fn ($families) => $families->pluck('id')->all());

        $found = [];
        $pending = $familyIds;
        while ($pending !== []) {
            $id = array_pop($pending);
            if (isset($found[$id])) {
                continue;
            }
            $found[$id] = true;
            array_push($pending, ...($children[$id] ?? []));
        }

        return array_keys($found);
    }

    /**
     * @return list<int>
     */
    private function identifiers(mixed $value, string $filter): array
    {
        $values = (array) $value;
        foreach ($values as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                throw ValidationException::withMessages(["filter.{$filter}" => [__('products.invalid_identifier')]]);
            }
        }

        return array_values(array_map('intval', $values));
    }

    private function flag(mixed $value, string $filter): bool
    {
        return match (true) {
            $value === true, $value === '1', $value === 1 => true,
            $value === false, $value === '0', $value === 0 => false,
            default => throw ValidationException::withMessages(["filter.{$filter}" => [__('products.invalid_boolean')]]),
        };
    }
}
