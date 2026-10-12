<?php

namespace App\Domain\Limits;

use App\Models\RegionDay;

/** Avant la prise de commande (lots 5 et 6) : aucune quantité en attente ni validée. */
final class NoClientUsage implements ClientUsage
{
    public function forRegionDay(RegionDay $regionDay): array
    {
        return [];
    }
}
