<?php

namespace App\Http\Resources\Reasons;

use App\Models\ReasonCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Motif actif d'une liste de choix (`ReasonOption`, contrat du lot 6, §4) :
 * type, code, libellé, commentaire exigé, ordre.
 *
 * @mixin ReasonCode
 */
class ReasonOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type->value,
            'code' => $this->code,
            'label' => $this->label,
            'requires_comment' => $this->requires_comment,
            'sort_order' => $this->sort_order,
        ];
    }
}
