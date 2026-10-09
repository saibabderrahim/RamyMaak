<?php

namespace App\Http\Requests\Products;

use App\Models\Product;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    use ProductFicheRules;

    // Contrôle des droits avant la validation : sans product.manage, 403 et non 422.
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->targetProduct());
    }

    protected function prepareForValidation(): void
    {
        $this->prepareFiche($this->targetProduct());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Le code article ne change pas (clé ERP) : renvoyé tel quel, il est accepté.
            'sku' => ['sometimes', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || mb_strtoupper(trim($value)) !== $this->targetProduct()->sku) {
                    $fail(__('products.sku_immutable'));
                }
            }],
            ...$this->ficheRules($this->targetProduct()),
        ];
    }

    public function targetProduct(): Product
    {
        /** @var Product */
        return $this->route('product');
    }
}
