<?php

namespace App\Http\Resources;

use App\Models\ClientCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Catégorie de clients ; `clients_count` compte les clients visibles par
 * l'utilisateur (périmètre, RG-S02).
 *
 * @mixin ClientCategory
 */
class ClientCategoryResource extends JsonResource
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
            'clients_count' => (int) ($this->clients_count ?? 0),
        ];
    }
}
