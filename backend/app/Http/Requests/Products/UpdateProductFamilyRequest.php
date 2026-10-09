<?php

namespace App\Http\Requests\Products;

use App\Models\Product;
use App\Models\ProductFamily;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Le rattachement à une famille parente est revérifié sous verrou par le
 * contrôleur (pas de boucle : ni elle-même, ni une de ses descendantes).
 */
class UpdateProductFamilyRequest extends FormRequest
{
    // Contrôle des droits avant la validation : sans product.manage, 403 et non 422.
    public function authorize(): bool
    {
        return $this->user()->can('manageFamilies', Product::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Le code ne change pas (clé des fichiers Excel) : renvoyé tel quel, il est accepté.
            'code' => ['sometimes', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || mb_strtoupper(trim($value)) !== $this->targetFamily()->code) {
                    $fail(__('products.families.code_immutable'));
                }
            }],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('product_families', 'id')],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    public function targetFamily(): ProductFamily
    {
        /** @var ProductFamily */
        return $this->route('family');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('products.families.attributes');
    }
}
