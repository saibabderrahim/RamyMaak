<?php

namespace App\Http\Resources;

use App\Domain\Referentials\PriceBook;
use App\Domain\Referentials\ProductPhotos;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Support\BusinessClock;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fiche produit (EF-PRD-01) avec le prix en vigueur aujourd'hui (RG-P01), le
 * prochain prix programmé (EF-PRD-03) et les photos, la principale d'abord
 * (EF-PRD-02).
 *
 * `price` : prix HT par UV, prix TTC (taux de la fiche, RG-P02) et prix
 * unitaire indicatif (prix de l'UV ÷ unités par UV), arrondis à 2 décimales
 * (RG-P04) ; montants indicatifs, la facture de l'ERP fait foi (RG-P05).
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /** Relations attendues par la ressource. */
    public const RELATIONS = ['family:id,code,name', 'media'];

    /**
     * Relations et prix du jour (en vigueur et suivant) chargés en une seule
     * requête, quelle que soit la taille de la page.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public static function prepare(Builder $query, ?CarbonInterface $day = null): Builder
    {
        $day ??= BusinessClock::today();
        $date = $day->format('Y-m-d');
        $current = fn (string $column) => ProductPrice::query()
            ->select($column)
            ->whereColumn('product_id', 'products.id')
            ->where('valid_from', '<=', $date)
            ->orderByDesc('valid_from')
            ->limit(1);
        $next = fn (string $column) => ProductPrice::query()
            ->select($column)
            ->whereColumn('product_id', 'products.id')
            ->where('valid_from', '>', $date)
            ->orderBy('valid_from')
            ->limit(1);

        return $query
            ->with(self::RELATIONS)
            ->withPriceOn($day)
            ->addSelect([
                'current_price_from' => $current('valid_from'),
                'next_price_ht' => $next('price_ht'),
                'next_price_from' => $next('valid_from'),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        [$current, $next] = $this->pricing();

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'short_name' => $this->short_name,
            'family' => $this->family === null ? null : [
                'id' => $this->family->id,
                'code' => $this->family->code,
                'name' => $this->family->name,
            ],
            'format' => $this->format,
            'sales_unit_label' => $this->sales_unit_label,
            'units_per_sales_unit' => $this->units_per_sales_unit,
            'sales_units_per_pallet' => $this->sales_units_per_pallet,
            'ean' => $this->ean,
            'vat_rate' => (float) $this->vat_rate,
            'is_active' => (bool) $this->is_active,
            'is_new' => (bool) $this->is_new,
            'is_featured' => (bool) $this->is_featured,
            'sort_order' => (int) $this->sort_order,
            'price' => $current === null ? null : $this->priceRef($current[0], $current[1]),
            'next_price' => $next === null ? null : [
                'price_ht' => self::amount(self::cents($next[0])),
                'valid_from' => $next[1],
            ],
            'photos' => ProductPhotoResource::ordered(app(ProductPhotos::class)->photos($this->resource)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Prix en vigueur et prix suivant : [montant HT, date d'effet] ou null.
     * Sans prepare(), relus par PriceBook.
     *
     * @return array{0: array{0: mixed, 1: string}|null, 1: array{0: mixed, 1: string}|null}
     */
    private function pricing(): array
    {
        $attributes = $this->resource->getAttributes();

        if (array_key_exists('current_price_ht', $attributes) && array_key_exists('next_price_ht', $attributes)) {
            return [
                $attributes['current_price_ht'] === null ? null
                    : [$attributes['current_price_ht'], substr((string) $attributes['current_price_from'], 0, 10)],
                $attributes['next_price_ht'] === null ? null
                    : [$attributes['next_price_ht'], substr((string) $attributes['next_price_from'], 0, 10)],
            ];
        }

        $prices = app(PriceBook::class);
        $today = BusinessClock::today();
        $current = $prices->priceOn($this->resource, $today);
        $next = $prices->nextPriceAfter($this->resource, $today);

        return [
            $current === null ? null : [$current->price_ht, $current->valid_from->format('Y-m-d')],
            $next === null ? null : [$next->price_ht, $next->valid_from->format('Y-m-d')],
        ];
    }

    /**
     * @return array{price_ht: float, price_ttc: float, unit_price_ht: float, valid_from: string}
     */
    private function priceRef(mixed $priceHt, string $validFrom): array
    {
        $cents = self::cents($priceHt);
        // Taux en points de base (19,00 % → 1 900) : calcul exact en entiers, arrondi au centime supérieur à partir de 0,5.
        $vat = (int) round((float) $this->vat_rate * 100);
        $units = max(1, (int) $this->units_per_sales_unit);

        return [
            'price_ht' => self::amount($cents),
            'price_ttc' => self::amount(intdiv($cents * (10000 + $vat) + 5000, 10000)),
            'unit_price_ht' => self::amount(intdiv(2 * $cents + $units, 2 * $units)),
            'valid_from' => $validFrom,
        ];
    }

    /** Montant DECIMAL(12,2) lu en base (chaîne ou nombre selon le moteur) → centimes. */
    private static function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private static function amount(int $cents): float
    {
        return $cents / 100;
    }
}
