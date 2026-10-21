<?php

namespace App\Http\Requests\Quotas;

use App\Domain\Quotas\QuotaBook;
use App\Models\RegionQuota;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Cellules de la matrice enregistrées en brouillon (RG-Q01, RG-Q03) :
 * `quantity` entière de 0 à 1 000 000, ou null pour annuler le brouillon.
 * Régions (actives, du périmètre) et produits (actifs) sont contrôlés par
 * QuotaBook::saveDrafts, dans sa transaction (422 sur `cells.N.*`).
 */
class SaveQuotaDraftsRequest extends FormRequest
{
    /** Taille d'un lot d'enregistrement (un collage Excel compris). */
    public const MAX_CELLS = 2000;

    // Contrôle des droits avant la validation : sans quota.edit, 403 et non 422.
    public function authorize(): bool
    {
        return $this->user()->can('edit', RegionQuota::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'cells' => ['bail', 'required', 'array', 'list', 'min:1', 'max:'.self::MAX_CELLS],
            'cells.*' => ['required', 'array'],
            // Entiers JSON stricts : ni « 12 », ni 12.5, ni true.
            'cells.*.region_id' => ['required', 'integer:strict'],
            'cells.*.product_id' => ['required', 'integer:strict'],
            'cells.*.quantity' => ['present', 'nullable', 'integer:strict', 'min:0', 'max:'.QuotaBook::MAX_QUANTITY],
        ];
    }

    /**
     * Une cellule (région, produit) au plus une fois par requête : l'erreur
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
                if (! is_array($cell) || ! is_int($cell['region_id'] ?? null) || ! is_int($cell['product_id'] ?? null)) {
                    continue;
                }
                $key = $cell['region_id'].'|'.$cell['product_id'];
                if (isset($seen[$key])) {
                    $validator->errors()->add("cells.{$index}.product_id", __('quotas.duplicate_cell', ['index' => $seen[$key]]));

                    continue;
                }
                $seen[$key] = $index;
            }
        }];
    }

    /**
     * Cellules validées, dans l'ordre reçu.
     *
     * @return list<array{region_id: int, product_id: int, quantity: ?int}>
     */
    public function cells(): array
    {
        return array_values(array_map(fn (array $cell) => [
            'region_id' => $cell['region_id'],
            'product_id' => $cell['product_id'],
            'quantity' => $cell['quantity'],
        ], $this->validated('cells')));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('quotas.attributes');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $quantity = __('quotas.quantity_format', ['max' => number_format(QuotaBook::MAX_QUANTITY, 0, ',', ' ')]);

        return [
            'list' => __('validation.array'),
            'cells.*.quantity.integer' => $quantity,
            'cells.*.quantity.min' => $quantity,
            'cells.*.quantity.max' => $quantity,
        ];
    }
}
