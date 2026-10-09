<?php

namespace App\Http\Resources;

use App\Models\ProductPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Prix HT d'une UV à partir d'une date d'effet (RG-P01) ; valid_to est la
 * veille du prix suivant.
 *
 * @mixin ProductPrice
 */
class ProductPriceResource extends JsonResource
{
    /** Relations attendues par la ressource. */
    public const RELATIONS = ['createdBy:id,username,name'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'price_ht' => round((float) $this->price_ht, 2),
            'valid_from' => $this->valid_from->format('Y-m-d'),
            'valid_to' => $this->valid_to?->format('Y-m-d'),
            'created_by' => $this->createdBy === null ? null : [
                'id' => $this->createdBy->id,
                'username' => $this->createdBy->username,
                'name' => $this->createdBy->name,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
