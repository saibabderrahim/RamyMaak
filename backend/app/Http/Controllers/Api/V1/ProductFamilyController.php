<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Products\StoreProductFamilyRequest;
use App\Http\Requests\Products\UpdateProductFamilyRequest;
use App\Http\Resources\ProductFamilyResource;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\User;
use App\Policies\ProductPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Familles et gammes de produits (EF-PRD-01) : arborescence par parent_id,
 * sans boucle. Lecture avec product.view, écriture avec product.manage.
 */
class ProductFamilyController extends Controller
{
    /** Toutes les familles (onglets du catalogue), triées par ordre d'affichage. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Product::class);

        $families = $this->withProductsCount(ProductFamily::query(), $request->user())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => ProductFamilyResource::collection($families)]);
    }

    public function store(StoreProductFamilyRequest $request): JsonResponse
    {
        $family = ProductFamily::create($request->validated());

        return (new ProductFamilyResource($this->reload($family, $request->user())))
            ->response()
            ->setStatusCode(201);
    }

    /** Modification partielle ; le code ne change pas (clé des fichiers Excel). */
    public function update(UpdateProductFamilyRequest $request, ProductFamily $family): ProductFamilyResource
    {
        $attributes = $request->safe()->except('code');

        DB::transaction(function () use ($family, $attributes): void {
            $parentId = $attributes['parent_id'] ?? null;

            if ($parentId !== null) {
                // Verrou sur l'arborescence : deux rattachements simultanés ne
                // peuvent pas former une boucle.
                $parents = ProductFamily::query()->lockForUpdate()->pluck('parent_id', 'id');

                if ($this->createsCycle($family->id, (int) $parentId, $parents)) {
                    throw ValidationException::withMessages(['parent_id' => [__('products.families.parent_cycle')]]);
                }
            }

            $family->fill($attributes)->save();
        });

        return new ProductFamilyResource($this->reload($family, $request->user()));
    }

    /**
     * Rattacher la famille à ce parent formerait-il une boucle ? Oui si le
     * parent est la famille elle-même ou l'une de ses descendantes.
     *
     * @param  Collection<int, int|null>  $parents  identifiant → parent_id
     */
    private function createsCycle(int $familyId, int $parentId, Collection $parents): bool
    {
        $seen = [];

        for ($current = $parentId; $current !== null; $current = $parents->get($current)) {
            if ($current === $familyId) {
                return true;
            }
            if (isset($seen[$current])) {
                // Boucle préexistante qui n'inclut pas la famille : sortie.
                return false;
            }
            $seen[$current] = true;
        }

        return false;
    }

    private function reload(ProductFamily $family, User $viewer): ProductFamily
    {
        return $this->withProductsCount(ProductFamily::query(), $viewer)->whereKey($family->getKey())->firstOrFail();
    }

    /**
     * Un compte client ne compte que les produits actifs.
     *
     * @param  Builder<ProductFamily>  $query
     * @return Builder<ProductFamily>
     */
    private function withProductsCount(Builder $query, User $viewer): Builder
    {
        $activeOnly = ProductPolicy::seesActiveCatalogOnly($viewer);

        return $query->withCount(['products' => fn (Builder $q) => $q->when($activeOnly, fn (Builder $q) => $q->where('is_active', true))]);
    }
}
