<?php

namespace App\Http\Resources;

use App\Models\Depot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Site d'expédition (option, EF-ADM-03) et nombre de régions qu'il dessert.
 *
 * @mixin Depot
 */
class DepotResource extends JsonResource
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
            'address' => $this->address,
            'is_active' => $this->is_active,
            'regions_count' => (int) ($this->resource->getAttribute('regions_count') ?? $this->regions()->count()),
        ];
    }
}
