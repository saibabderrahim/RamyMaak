<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Referentials\ProductPhotos;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\StoreProductPhotoRequest;
use App\Http\Requests\Products\UpdateProductPhotoRequest;
use App\Http\Resources\ProductPhotoResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Photos d'un produit (EF-PRD-02, §9.8) : envoi (type réel contrôlé, image
 * ré-encodée, variantes WebP), choix de la principale, suppression. Le média
 * est lié au produit de l'adresse (liaison imbriquée des routes) : 404 sinon.
 */
class ProductMediaController extends Controller
{
    public function __construct(private readonly ProductPhotos $photos) {}

    public function store(StoreProductPhotoRequest $request, Product $product): JsonResponse
    {
        $photo = $this->photos->add($product, $request->file('photo'), $request->boolean('main'));
        $isMain = $this->photos->photos($product)->first()?->is($photo) ?? false;

        return (new ProductPhotoResource($photo))->main($isMain)->response()->setStatusCode(201);
    }

    /** `main: true` : la photo passe en tête ; renvoie toutes les photos réordonnées. */
    public function update(UpdateProductPhotoRequest $request, Product $product, Media $media): JsonResponse
    {
        $this->ensurePhoto($media);

        return response()->json(['data' => ProductPhotoResource::ordered($this->photos->makeMain($product, $media))]);
    }

    public function destroy(Product $product, Media $media): Response
    {
        Gate::authorize('update', $product);
        $this->ensurePhoto($media);

        $this->photos->delete($product, $media);

        return response()->noContent();
    }

    private function ensurePhoto(Media $media): void
    {
        abort_unless($media->collection_name === Product::PHOTOS, 404);
    }
}
