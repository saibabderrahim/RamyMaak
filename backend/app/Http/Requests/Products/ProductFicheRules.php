<?php

namespace App\Http\Requests\Products;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Règles communes de la fiche produit (EF-PRD-01), en création ($product
 * null) comme en modification partielle.
 *
 * @mixin FormRequest
 */
trait ProductFicheRules
{
    /** Longueur de la désignation courte (affichage mobile). */
    public const SHORT_NAME_LENGTH = 60;

    /**
     * @return array<string, list<mixed>>
     */
    protected function ficheRules(?Product $product): array
    {
        $optional = $product === null ? [] : ['sometimes'];
        $mandatory = [...$optional, 'required'];

        return [
            'name' => [...$mandatory, 'string', 'max:255'],
            'short_name' => [...$optional, 'nullable', 'string', 'max:'.self::SHORT_NAME_LENGTH],
            'family_id' => [...$mandatory, 'integer', Rule::exists('product_families', 'id')],
            'format' => [...$optional, 'nullable', 'string', 'max:30'],
            'sales_unit_label' => [...$mandatory, 'string', 'max:60'],
            'units_per_sales_unit' => [...$mandatory, 'integer', 'min:1', 'max:65535'],
            'sales_units_per_pallet' => [...$optional, 'nullable', 'integer', 'min:1', 'max:65535'],
            'ean' => [...$optional, 'nullable', 'string', 'regex:/^\d{8,14}$/'],
            'vat_rate' => [...$mandatory, 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'is_active' => [...$optional, 'boolean'],
            'is_new' => [...$optional, 'boolean'],
            'is_featured' => [...$optional, 'boolean'],
            'sort_order' => [...$optional, 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /**
     * Désignation courte par défaut : la désignation tronquée à 60 caractères.
     * Un code EAN saisi comme nombre est lu comme texte.
     */
    protected function prepareFiche(?Product $product): void
    {
        if (is_int($this->input('ean'))) {
            $this->merge(['ean' => (string) $this->input('ean')]);
        }

        $creating = $product === null;
        if (! $creating && ! $this->has('short_name')) {
            return;
        }

        if (filled($this->input('short_name'))) {
            return;
        }

        $name = $this->has('name') ? $this->input('name') : $product?->name;
        if (is_string($name) && trim($name) !== '') {
            $this->merge(['short_name' => rtrim(mb_substr(trim($name), 0, self::SHORT_NAME_LENGTH))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('products.attributes');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sku.regex' => __('products.sku_format'),
            'ean.regex' => __('products.ean_format'),
        ];
    }
}
