<?php

namespace App\Domain\Quotas;

use RuntimeException;

/**
 * Mise à jour conditionnelle du compteur V(r,p) refusée (§5.8 étape 2) :
 * V(r,p) + q dépasserait Q(r,p). Filet de QuotaLedger::reserve, traduit en
 * REGION_QUOTA_EXCEEDED par l'appelant ; le contrôle préalable sous verrou
 * l'attrape normalement avant.
 */
final class QuotaExceeded extends RuntimeException
{
    public function __construct(
        public readonly int $regionDayId,
        public readonly int $productId,
        public readonly int $quantity,
    ) {
        parent::__construct("Quota dépassé : journée régionale {$regionDayId}, produit {$productId}, +{$quantity}.");
    }
}
