<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Photo produit (EF-PRD-02) : adresses des variantes WebP (§9.8), servies à
 * /media par le serveur web. La principale est la première de la collection.
 *
 * @mixin Media
 */
class ProductPhotoResource extends JsonResource
{
    private bool $isMain = false;

    /**
     * Photos dans l'ordre de la collection, la première marquée principale.
     *
     * @param  Collection<int, Media>  $photos
     * @return list<self>
     */
    public static function ordered(Collection $photos): array
    {
        return $photos->values()
            ->map(fn (Media $photo, int $index) => (new self($photo))->main($index === 0))
            ->all();
    }

    public function main(bool $isMain): self
    {
        $this->isMain = $isMain;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'is_main' => $this->isMain,
            'urls' => collect(array_keys(Product::PHOTO_SIZES))
                ->mapWithKeys(fn (string $conversion) => [$conversion => $this->resource->getUrl($conversion)])
                ->all(),
        ];
    }
}
