<?php

namespace App\Http\Requests\Products;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductFamilyRequest extends FormRequest
{
    // Contrôle des droits avant la validation : sans product.manage, 403 et non 422.
    public function authorize(): bool
    {
        return $this->user()->can('manageFamilies', Product::class);
    }

    protected function prepareForValidation(): void
    {
        // Code repris par l'import du catalogue (code_famille) : en majuscules.
        if (is_string($this->input('code'))) {
            $this->merge(['code' => mb_strtoupper(trim($this->input('code')))]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^[A-Z0-9_-]{1,20}$/', Rule::unique('product_families', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', Rule::exists('product_families', 'id')],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('products.families.attributes');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['code.regex' => __('products.families.code_format')];
    }
}
