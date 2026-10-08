<?php

namespace App\Domain\Referentials;

use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Historique des prix (RG-P01, EF-PRD-03) : chaque prix HT par UV a une
 * date d'effet ; à une date donnée, le prix en vigueur est celui dont la
 * date d'effet est la plus récente sans la dépasser. valid_to (veille du
 * prix suivant) est recalculé à chaque changement.
 */
final class PriceBook
{
    public function priceOn(Product $product, CarbonInterface $day): ?ProductPrice
    {
        return ProductPrice::query()
            ->where('product_id', $product->getKey())
            ->where('valid_from', '<=', $day->format('Y-m-d'))
            ->orderByDesc('valid_from')
            ->first();
    }

    /** Premier prix programmé après le jour donné (prix futur). */
    public function nextPriceAfter(Product $product, CarbonInterface $day): ?ProductPrice
    {
        return ProductPrice::query()
            ->where('product_id', $product->getKey())
            ->where('valid_from', '>', $day->format('Y-m-d'))
            ->orderBy('valid_from')
            ->first();
    }

    /**
     * Enregistre le prix à partir d'une date d'effet ; un prix existant à la
     * même date est remplacé.
     */
    public function setPrice(Product $product, string|int|float $priceHt, CarbonInterface $validFrom, ?User $by): ProductPrice
    {
        $amount = self::normalizeAmount($priceHt);

        return DB::transaction(function () use ($product, $amount, $validFrom, $by) {
            // Sérialise les changements de prix d'un même produit.
            Product::query()->whereKey($product->getKey())->lockForUpdate()->first();

            $price = ProductPrice::query()->firstOrNew([
                'product_id' => $product->getKey(),
                'valid_from' => $validFrom->format('Y-m-d'),
            ]);
            $price->price_ht = $amount;
            if (! $price->exists) {
                $price->created_by = $by?->getKey();
            }
            $price->save();

            $this->recomputeValidity($product);

            return $price->refresh();
        });
    }

    public function recomputeValidity(Product $product): void
    {
        $prices = ProductPrice::query()
            ->where('product_id', $product->getKey())
            ->orderBy('valid_from')
            ->get()
            ->values();

        foreach ($prices as $index => $price) {
            $next = $prices->get($index + 1);
            $validTo = $next?->valid_from->subDay()->format('Y-m-d');

            if ($price->valid_to?->format('Y-m-d') !== $validTo) {
                // Donnée dérivée : pas d'entrée d'audit pour la recalculer.
                $price->forceFill(['valid_to' => $validTo])->saveQuietly();
            }
        }
    }

    /**
     * Montant positif à deux décimales au plus, rendu « 1234.50 ».
     */
    public static function normalizeAmount(string|int|float $value): string
    {
        if (is_int($value) || is_float($value)) {
            // Les cellules Excel numériques peuvent porter un bruit de calcul flottant.
            if ($value < 0 || ! is_finite((float) $value) || abs($value - round($value, 2)) > 1e-6) {
                throw new InvalidArgumentException("Montant invalide : {$value}");
            }

            return number_format(round($value, 2), 2, '.', '');
        }

        $text = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], trim($value));

        if (preg_match('/^\d+(\.\d{1,2})?$/', $text) !== 1) {
            throw new InvalidArgumentException("Montant invalide : {$value}");
        }

        return number_format((float) $text, 2, '.', '');
    }
}
