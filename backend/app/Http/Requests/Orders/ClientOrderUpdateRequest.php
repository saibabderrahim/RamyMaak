<?php

namespace App\Http\Requests\Orders;

/**
 * Modification d'une commande envoyée (RG-C05, I-51) : remplacement complet
 * des lignes et du commentaire (clé requise, null permis), avec la
 * `version` affichée (entier JSON strict).
 */
class ClientOrderUpdateRequest extends ClientOrderWriteRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer:strict', 'min:1'],
            'comment' => ['present', ...$this->commentRules()],
            ...$this->lineRules(),
        ];
    }

    public function version(): int
    {
        return (int) $this->validated('version');
    }
}
