<?php

namespace App\Http\Resources;

use App\Models\ProductFamily;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Famille ou gamme de produits (EF-PRD-01). `products_count` est fourni par
 * la requête (withCount) : un compte client n'y compte que les produits
 * actifs.
 *
 * @mixin ProductFamily
 */
class ProductFamilyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            'sort_order' => $this->sort_order,
            'products_count' => (int) ($this->products_count ?? $this->products()->count()),
        ];
    }
}
