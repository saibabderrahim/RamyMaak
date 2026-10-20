<?php

namespace App\Domain\Documents;

/**
 * Totaux d'un groupe ou du bon de préparation : produits, UV, commandes
 * distinctes et équivalent palettes (somme des quantités ramenées au
 * colisage, `null` si aucun produit n'a de colisage).
 */
final readonly class PickingTotals
{
    public function __construct(
        public int $products,
        public int $quantity,
        public int $orders,
        public ?float $pallets,
    ) {}
}
