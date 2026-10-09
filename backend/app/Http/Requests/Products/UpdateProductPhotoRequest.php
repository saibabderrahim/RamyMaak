<?php

namespace App\Http\Requests\Products;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Désignation de la photo principale (la première de la collection).
 */
class UpdateProductPhotoRequest extends FormRequest
{
    // Contrôle des droits avant la validation : sans product.manage, 403 et non 422.
    public function authorize(): bool
    {
        /** @var Product $product */
        $product = $this->route('product');

        return $this->user()->can('update', $product);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'main' => ['required', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('products.photos.attributes');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['main.accepted' => __('products.photos.main_only')];
    }
}
