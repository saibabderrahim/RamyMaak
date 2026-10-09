<?php

namespace App\Http\Requests\Products;

use App\Domain\Referentials\PriceBook;
use App\Models\Product;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

/**
 * Nouveau prix HT par UV à partir d'une date d'effet (RG-P01, EF-PRD-03) :
 * passée, présente ou future (prix programmé).
 */
class StoreProductPriceRequest extends FormRequest
{
    /** Plafond de la colonne product_prices.price_ht (DECIMAL 12,2). */
    private const MAX_AMOUNT = '9999999999.99';

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
            'price_ht' => ['bail', 'required', 'numeric', 'min:0', 'max:'.self::MAX_AMOUNT,
                function (string $attribute, mixed $value, Closure $fail): void {
                    try {
                        PriceBook::normalizeAmount($value);
                    } catch (InvalidArgumentException) {
                        $fail(__('products.prices.amount_format'));
                    }
                },
            ],
            'valid_from' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /** Montant normalisé « 1234.50 ». */
    public function amount(): string
    {
        return PriceBook::normalizeAmount($this->validated('price_ht'));
    }

    public function validFrom(): CarbonImmutable
    {
        /** @var CarbonImmutable */
        return BusinessClock::parseDay((string) $this->validated('valid_from'));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('products.prices.attributes');
    }
}
