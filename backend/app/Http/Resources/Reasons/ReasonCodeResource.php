<?php

namespace App\Http\Resources\Reasons;

use App\Models\ReasonCode;
use Illuminate\Http\Request;

/**
 * Motif vu par l'Admin (`ReasonCode`, contrat du lot 6, §4) : la forme d'un
 * choix (ReasonOptionResource) avec son identifiant, son activation et ses
 * dates.
 *
 * @mixin ReasonCode
 */
class ReasonCodeResource extends ReasonOptionResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            ...parent::toArray($request),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
