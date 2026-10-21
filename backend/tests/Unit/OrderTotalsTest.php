<?php

use App\Domain\Orders\OrderTotals;
use App\Support\Money;

// Totaux d'une commande (RG-P04) : mêmes valeurs que ClientOrdersTest et
// shared/money.test.ts ; parité exacte avec l'algorithme du lot 5 (extrait de
// ClientOrders sans changement).

it('computes the totals line by line, VAT rounded half up to the cent (RG-P04)', function () {
    $totals = OrderTotals::of([
        ['unit_price_ht' => '900.00', 'vat_rate' => '19.00', 'qty' => 40],
        ['unit_price_ht' => '850.50', 'vat_rate' => '19.00', 'qty' => 3],
        ['unit_price_ht' => '120.00', 'vat_rate' => '9.00', 'qty' => 7],
    ]);

    expect($totals)->toBe(['ht' => 3939150, 'vat' => 740039, 'ttc' => 4679189])
        ->and(OrderTotals::columns($totals))->toBe(['total_ht' => 39391.5, 'total_vat' => 7400.39, 'total_ttc' => 46791.89])
        ->and(OrderTotals::of([]))->toBe(['ht' => 0, 'vat' => 0, 'ttc' => 0])
        // Une ligne validée à 0 ne compte pas.
        ->and(OrderTotals::of([['unit_price_ht' => '850.50', 'vat_rate' => '19.00', 'qty' => 0]]))->toBe(['ht' => 0, 'vat' => 0, 'ttc' => 0]);
});

it('matches the lot-5 algorithm exactly on random orders', function () {
    // Algorithme de ClientOrders::totals au lot 5, recopié tel quel.
    $lot5 = function (array $lines): array {
        $ht = 0;
        $vat = 0;
        foreach ($lines as $line) {
            $lineHt = Money::cents($line['unit_price_ht']) * $line['requested_qty'];
            $ht += $lineHt;
            $vat += Money::vatCents($lineHt, Money::basisPoints($line['vat_rate']));
        }

        return ['ht' => $ht, 'vat' => $vat];
    };

    mt_srand(20261019);
    for ($order = 0; $order < 200; $order++) {
        $lines = [];
        for ($i = 0, $count = mt_rand(1, 30); $i < $count; $i++) {
            $lines[] = [
                'unit_price_ht' => number_format(mt_rand(1, 99_999_99) / 100, 2, '.', ''),
                'vat_rate' => ['0.00', '9.00', '19.00'][mt_rand(0, 2)],
                'requested_qty' => mt_rand(1, 5000),
            ];
        }
        $expected = $lot5($lines);
        $totals = OrderTotals::of(array_map(fn (array $line) => [...$line, 'qty' => $line['requested_qty']], $lines));

        expect($totals)->toBe([...$expected, 'ttc' => $expected['ht'] + $expected['vat']]);
    }
});
