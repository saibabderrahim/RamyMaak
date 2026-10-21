<?php

namespace App\Http\Requests\Limits;

use App\Domain\Access\Permission;
use App\Domain\Limits\LimitBook;
use Illuminate\Validation\Validator;

/**
 * Bornes enregistrées en brouillon (RG-B01, RG-B09) : `limit` entière de 0
 * à 1 000 000, ou null pour « pas de borne ». Client de la région ce
 * jour-là, produit disponible, borne au plus égale au quota (RG-B02,
 * RG-B03) et au moins égale au validé après l'envoi (RG-B07) sont contrôlés
 * par LimitBook::saveDrafts, dans sa transaction (422 sur `cells.N.*`).
 */
class SaveLimitDraftsRequest extends RegionalWriteRequest
{
    /** Taille d'un lot d'enregistrement (un collage Excel compris). */
    public const MAX_CELLS = 2000;

    protected function permission(): Permission
    {
        return Permission::LimitEdit;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->regionRules(),
            'cells' => ['bail', 'required', 'array', 'list', 'min:1', 'max:'.self::MAX_CELLS],
            'cells.*' => ['required', 'array'],
            // Entiers JSON stricts : ni « 12 », ni 12.5, ni true.
            'cells.*.client_id' => ['required', 'integer:strict'],
            'cells.*.product_id' => ['required', 'integer:strict'],
            'cells.*.limit' => ['present', 'nullable', 'integer:strict', 'min:0', 'max:'.LimitBook::MAX_LIMIT],
        ];
    }

    /**
     * Une cellule (client, produit) au plus une fois par requête : l'erreur
     * porte sur la seconde occurrence.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $cells = $this->input('cells');
            if (! is_array($cells)) {
                return;
            }

            $seen = [];
            foreach ($cells as $index => $cell) {
                if (! is_array($cell) || ! is_int($cell['client_id'] ?? null) || ! is_int($cell['product_id'] ?? null)) {
                    continue;
                }
                $key = $cell['client_id'].'|'.$cell['product_id'];
                if (isset($seen[$key])) {
                    $validator->errors()->add("cells.{$index}.product_id", __('limits.duplicate_cell', ['index' => $seen[$key]]));

                    continue;
                }
                $seen[$key] = $index;
            }
        }];
    }

    /**
     * Cellules validées, dans l'ordre reçu (les erreurs de LimitBook portent les mêmes indices).
     *
     * @return list<array{client_id: int, product_id: int, limit: ?int}>
     */
    public function cells(): array
    {
        return array_values(array_map(fn (array $cell) => [
            'client_id' => $cell['client_id'],
            'product_id' => $cell['product_id'],
            'limit' => $cell['limit'],
        ], $this->validated('cells')));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $limit = __('limits.limit_format', ['max' => number_format(LimitBook::MAX_LIMIT, 0, ',', ' ')]);

        return [
            'list' => __('validation.array'),
            'cells.*.limit.integer' => $limit,
            'cells.*.limit.min' => $limit,
            'cells.*.limit.max' => $limit,
        ];
    }
}
