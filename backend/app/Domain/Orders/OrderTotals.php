<?php

namespace App\Domain\Orders;

use App\Support\Money;

/**
 * Totaux d'une commande en centimes (RG-P04) : HT par ligne exact (prix figé
 * × quantité), TVA par ligne arrondie au centime (demi supérieur), totaux =
 * somme des lignes. Mêmes règles que la PWA (shared/money.ts). À l'envoi et à
 * la modification sur les quantités demandées ; dès la validation, sur les
 * quantités validées (RG-P04, D-11).
 */
final class OrderTotals
{
    /**
     * @param  iterable<array{unit_price_ht: mixed, vat_rate: mixed, qty: int}>  $lines
     * @return array{ht: int, vat: int, ttc: int} centimes
     */
    public static function of(iterable $lines): array
    {
        $ht = 0;
        $vat = 0;
        foreach ($lines as $line) {
            $lineHt = Money::cents($line['unit_price_ht']) * $line['qty'];
            $ht += $lineHt;
            $vat += Money::vatCents($lineHt, Money::basisPoints($line['vat_rate']));
        }

        return ['ht' => $ht, 'vat' => $vat, 'ttc' => $ht + $vat];
    }

    /**
     * Colonnes de la commande (DA, 2 décimales).
     *
     * @param  array{ht: int, vat: int, ttc: int}  $totals
     * @return array{total_ht: float, total_vat: float, total_ttc: float}
     */
    public static function columns(array $totals): array
    {
        return [
            'total_ht' => Money::amount($totals['ht']),
            'total_vat' => Money::amount($totals['vat']),
            'total_ttc' => Money::amount($totals['ttc']),
        ];
    }
}
