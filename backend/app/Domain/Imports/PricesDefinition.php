<?php

namespace App\Domain\Imports;

use App\Domain\Referentials\PriceBook;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Support\BusinessClock;
use InvalidArgumentException;

/**
 * Import des prix (Annexe B.5, EF-PRD-03), clé code_produit + date_effet.
 * Chaque prix passe par PriceBook::setPrice (RG-P01) : un prix à la même
 * date d'effet est remplacé, les périodes de validité sont recalculées.
 */
final class PricesDefinition extends ImportDefinition
{
    /** Plus grand montant d'une colonne decimal(12, 2). */
    private const MAX_AMOUNT = 9999999999.99;

    /** @var array<string, Product> code → produit */
    private array $products = [];

    /** @var array<string, string> « id produit|AAAA-MM-JJ » → prix HT actuel */
    private array $prices = [];

    public function __construct(User $actor, private readonly PriceBook $priceBook)
    {
        parent::__construct($actor);
    }

    public function columns(): array
    {
        return ['code_produit', 'prix_ht', 'date_effet'];
    }

    public function requiredColumns(): array
    {
        return ['code_produit', 'prix_ht', 'date_effet'];
    }

    /** Désignation du produit, pour se repérer dans le gabarit. */
    public function informationColumns(): array
    {
        return ['designation'];
    }

    public function keyColumn(): string
    {
        return 'code_produit';
    }

    public function key(RowContext $row): ?string
    {
        $code = $this->code($row, 'code_produit');
        $date = $this->date($row, 'date_effet');

        return $code === null || $date === null ? null : $code.'|'.$date->format('Y-m-d');
    }

    public function label(string $key): string
    {
        [$code, $day] = explode('|', $key, 2);

        return __('imports.subjects.price_key', ['code' => $code, 'date' => BusinessClock::parseDay($day)?->format('d/m/Y')]);
    }

    public function describe(string $key): string
    {
        return __('imports.subjects.price', ['key' => $this->label($key)]);
    }

    public function preload(array $keys): void
    {
        $codes = array_values(array_unique(array_map(fn (string $key) => explode('|', $key, 2)[0], $keys)));

        $this->products = [];
        $this->prices = [];
        foreach (array_chunk($codes, 500) as $chunk) {
            $products = Product::query()->whereIn('sku', $chunk)->get();
            foreach ($products as $product) {
                $this->products[mb_strtoupper($product->sku)] = $product;
            }

            foreach (ProductPrice::query()->whereIn('product_id', $products->modelKeys())->get() as $price) {
                $this->prices[$price->product_id.'|'.$price->valid_from->format('Y-m-d')] = $price->price_ht;
            }
        }
    }

    public function check(RowContext $row, string $key): ?RowPlan
    {
        [$code, $day] = explode('|', $key, 2);

        $product = $this->products[$code] ?? null;
        if ($product === null) {
            $row->fail('code_produit', __('imports.errors.unknown_product', ['value' => $code]));
        }

        $amount = $this->amount($row);
        if ($row->hasErrors() || $product === null || $amount === null) {
            return null;
        }

        $current = $this->prices[$product->id.'|'.$day] ?? null;
        $changes = $current === $amount ? [] : ['price_ht' => ['old' => $current === null ? null : (float) $current, 'new' => (float) $amount]];
        if ($current === null) {
            $changes['valid_from'] = ['old' => null, 'new' => $day];
        }

        return new RowPlan(
            $row->line,
            $key,
            $this->label($key),
            match (true) {
                $current === null => RowPlan::CREATE,
                $changes === [] => RowPlan::UNCHANGED,
                default => RowPlan::UPDATE,
            },
            $changes,
            ['price_ht' => $amount, 'valid_from' => $day],
            $product,
        );
    }

    /** Code ou date manquant ou invalide : le montant est contrôlé quand même. */
    public function checkWithoutKey(RowContext $row): void
    {
        $this->amount($row);
    }

    public function apply(RowPlan $plan): void
    {
        /** @var Product $product */
        $product = $plan->record;
        $validFrom = BusinessClock::parseDay($plan->attributes['valid_from']);

        $this->priceBook->setPrice($product, $plan->attributes['price_ht'], $validFrom, $this->actor);
    }

    /**
     * Prix en vigueur aujourd'hui et prix programmés : réimporter le gabarit
     * tel quel ne change rien.
     */
    public function templateRows(): iterable
    {
        $today = BusinessClock::today()->format('Y-m-d');

        $prices = ProductPrice::query()
            ->join('products', 'products.id', '=', 'product_prices.product_id')
            ->where(fn ($q) => $q->whereNull('product_prices.valid_to')->orWhere('product_prices.valid_to', '>=', $today))
            ->orderBy('products.sku')
            ->orderBy('product_prices.valid_from')
            ->select('product_prices.*', 'products.sku', 'products.name as product_name');

        foreach ($prices->lazy(500) as $price) {
            yield [$price->getAttribute('sku'), (float) $price->price_ht, $price->valid_from, $price->getAttribute('product_name')];
        }
    }

    /** Montant HT positif à deux décimales (PriceBook::normalizeAmount). */
    private function amount(RowContext $row): ?string
    {
        if (! $row->filled('prix_ht')) {
            if (! $row->failed('prix_ht')) {
                $row->fail('prix_ht', __('imports.errors.required'));
            }

            return null;
        }

        $cell = $row->cell('prix_ht');
        try {
            $amount = is_string($cell->value) || $cell->isNumeric()
                ? PriceBook::normalizeAmount($cell->value)
                : null;
        } catch (InvalidArgumentException) {
            $amount = null;
        }

        if ($amount === null || (float) $amount > self::MAX_AMOUNT) {
            $row->fail('prix_ht', __('imports.errors.amount', ['value' => $cell->display()]));

            return null;
        }

        return $amount;
    }
}
