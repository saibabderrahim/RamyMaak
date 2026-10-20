<?php

namespace App\Domain\Documents;

/**
 * Ligne du bon de préparation : un produit d'un groupe (région ou dépôt),
 * quantité validée cumulée en UV, nombre de commandes et palettes.
 */
final readonly class PickingLine
{
    public function __construct(
        public string $family,
        public string $sku,
        public string $name,
        public string $unitLabel,
        public int $quantity,
        public int $orders,
        public ?Pallets $pallets,
    ) {}
}
