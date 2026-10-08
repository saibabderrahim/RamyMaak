<?php

use App\Domain\Referentials\PriceBook;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;

function prices(): PriceBook
{
    return app(PriceBook::class);
}

function day(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date, BusinessClock::timezone());
}

it('returns the price in force on a date (RG-P01)', function () {
    $product = Product::factory()->create();
    prices()->setPrice($product, '900', day('2026-09-01'), null);
    prices()->setPrice($product, '950', day('2026-11-01'), null);

    expect(prices()->priceOn($product, day('2026-08-31')))->toBeNull()
        ->and(prices()->priceOn($product, day('2026-09-01'))->price_ht)->toBe('900.00')
        ->and(prices()->priceOn($product, day('2026-10-31'))->price_ht)->toBe('900.00')
        ->and(prices()->priceOn($product, day('2026-11-01'))->price_ht)->toBe('950.00')
        ->and(prices()->nextPriceAfter($product, day('2026-10-08'))->price_ht)->toBe('950.00');
});

it('keeps validity periods contiguous, even when a price is inserted in between', function () {
    $product = Product::factory()->create();
    prices()->setPrice($product, '900', day('2026-09-01'), null);
    prices()->setPrice($product, '1000', day('2026-12-01'), null);
    prices()->setPrice($product, '950', day('2026-11-01'), null);

    $periods = ProductPrice::where('product_id', $product->id)->orderBy('valid_from')->get()
        ->map(fn ($p) => [$p->valid_from->format('Y-m-d'), $p->valid_to?->format('Y-m-d')])->all();

    expect($periods)->toBe([
        ['2026-09-01', '2026-10-31'],
        ['2026-11-01', '2026-11-30'],
        ['2026-12-01', null],
    ]);
});

it('replaces the price of an existing effective date instead of duplicating it', function () {
    $product = Product::factory()->create();
    prices()->setPrice($product, '900', day('2026-11-01'), null);
    prices()->setPrice($product, '920.5', day('2026-11-01'), null);

    expect(ProductPrice::where('product_id', $product->id)->count())->toBe(1)
        ->and(prices()->priceOn($product, day('2026-11-02'))->price_ht)->toBe('920.50');
});

it('adds the price in force to product queries', function () {
    $priced = Product::factory()->create();
    $unpriced = Product::factory()->create();
    prices()->setPrice($priced, '900', day('2026-09-01'), null);
    prices()->setPrice($priced, '950', day('2026-11-01'), null);

    $rows = Product::query()->withPriceOn(day('2026-10-08'))->get()->keyBy('id');

    expect((float) $rows[$priced->id]->current_price_ht)->toBe(900.0)
        ->and($rows[$unpriced->id]->current_price_ht)->toBeNull();
});

it('normalizes amounts typed in French or read from Excel', function (string|int|float $input, string $expected) {
    expect(PriceBook::normalizeAmount($input))->toBe($expected);
})->with([
    ['900', '900.00'],
    ['900,5', '900.50'],
    ["1\u{00A0}071,00", '1071.00'],
    ['1 850.25', '1850.25'],
    [1071, '1071.00'],
    [1071.0000000001, '1071.00'],
    [0, '0.00'],
]);

it('rejects invalid amounts', function (string|int|float $input) {
    expect(fn () => PriceBook::normalizeAmount($input))->toThrow(InvalidArgumentException::class);
})->with(['-5', -5, '12.345', 12.345, 'abc', '', '1e3']);
