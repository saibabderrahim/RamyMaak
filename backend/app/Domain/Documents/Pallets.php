<?php

namespace App\Domain\Documents;

/**
 * Équivalent palettes d'une quantité en UV (EF-PRD-01, RG-A04) : palettes
 * pleines, reste en UV et équivalent décimal à deux décimales. Sans colisage
 * renseigné (`sales_units_per_pallet` absent ou nul), il n'y a pas
 * d'équivalent : le bon de préparation montre « — ».
 */
final readonly class Pallets
{
    private function __construct(
        public int $full,
        public int $remainder,
        public float $decimal,
    ) {}

    public static function of(int $quantity, ?int $perPallet): ?self
    {
        if ($perPallet === null || $perPallet < 1) {
            return null;
        }
        $quantity = max(0, $quantity);

        return new self(intdiv($quantity, $perPallet), $quantity % $perPallet, round($quantity / $perPallet, 2));
    }
}
