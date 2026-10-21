<?php

namespace App\Domain\Documents;

/**
 * Groupe du bon de préparation : une région, ou un dépôt (« Sans dépôt »
 * pour les régions qui n'en ont pas, `code` nul) avec les régions qu'il
 * réunit, ses lignes par famille puis ordre des produits, et ses totaux.
 */
final readonly class PickingGroup
{
    /**
     * @param  list<string>  $regions  noms des régions du groupe
     * @param  list<PickingLine>  $lines
     */
    public function __construct(
        public string $label,
        public ?string $code,
        public array $regions,
        public array $lines,
        public PickingTotals $totals,
    ) {}
}
