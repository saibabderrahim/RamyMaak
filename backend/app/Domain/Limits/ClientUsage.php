<?php

namespace App\Domain\Limits;

use App\Models\RegionDay;

/**
 * Consommation des clients d'une région pour une journée (§5.1) : quantités
 * en attente S(c,p) (commandes envoyées) et validées V(c,p), lues sur les
 * commandes (App\Domain\Orders\OrderUsage).
 */
interface ClientUsage
{
    /**
     * @return array<int, array<int, array{pending: int, validated: int}>> client → produit → S et V
     */
    public function forRegionDay(RegionDay $regionDay): array;
}
