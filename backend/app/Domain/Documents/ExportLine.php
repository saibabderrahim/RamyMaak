<?php

namespace App\Domain\Documents;

/**
 * Ligne de l'export des commandes de la journée : une ligne de commande, son
 * produit et les montants (prix HT figé × quantité validée, RG-P04). Les
 * quantités absentes (commande non décidée, non expédiée) restent nulles.
 */
final readonly class ExportLine
{
    public function __construct(
        public ExportOrder $order,
        public string $sku,
        public string $product,
        public string $unitLabel,
        public int $requested,
        public ?int $validated,
        public ?int $shipped,
        public ?string $adjustmentReason,
        public ?string $gapReason,
        public float $unitPriceHt,
        public float $vatRate,
        public ?float $amountHt,
    ) {}
}
