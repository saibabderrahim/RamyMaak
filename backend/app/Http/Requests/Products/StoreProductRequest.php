<?php

namespace App\Http\Requests\Products;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    use ProductFicheRules;

    // Contrôle des droits avant la validation : sans product.manage, 403 et non 422.
    public function authorize(): bool
    {
        return $this->user()->can('create', Product::class);
    }

    protected function prepareForValidation(): void
    {
        // Code ERP, clé des fichiers Excel : conservé en majuscules.
        if (is_string($this->input('sku'))) {
            $this->merge(['sku' => mb_strtoupper(trim($this->input('sku')))]);
        }

        $this->prepareFiche(null);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'regex:/^[A-Z0-9_-]{1,30}$/', Rule::unique('products', 'sku')],
            ...$this->ficheRules(null),
        ];
    }
}
