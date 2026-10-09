<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Referentials\PriceBook;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\StoreProductPriceRequest;
use App\Http\Resources\ProductPriceResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Historique des prix d'un produit (RG-P01, EF-PRD-03) : consultation par le
 * personnel, saisie unitaire avec product.manage par PriceBook (une même date
 * d'effet remplace le prix existant ; périodes de validité recalculées).
 */
class ProductPriceController extends Controller
{
    public function __construct(private readonly PriceBook $prices) {}

    /** Historique complet, prix les plus récents d'abord (prix futurs compris). */
    public function index(Product $product): JsonResponse
    {
        Gate::authorize('viewPrices', $product);

        $prices = $product->prices()
            ->reorder()
            ->with(ProductPriceResource::RELATIONS)
            ->orderByDesc('valid_from')
            ->get();

        return response()->json(['data' => ProductPriceResource::collection($prices)]);
    }

    public function store(StoreProductPriceRequest $request, Product $product): JsonResponse
    {
        $price = $this->prices->setPrice($product, $request->amount(), $request->validFrom(), $request->user());

        return (new ProductPriceResource($price->load(ProductPriceResource::RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }
}
