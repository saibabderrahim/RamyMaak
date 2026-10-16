<?php

namespace App\Domain\Orders;

/**
 * Disponible client D(c,p) (§5.1) : quantité maximale que le client peut
 * encore demander, contrôlée à l'envoi (RG-C03). Seule implémentation côté
 * serveur.
 *
 * - borne B : max(0, min(B − E, R)) ;
 * - sans borne, politique LIBRE : max(0, R) ;
 * - sans borne, politique OBLIGATOIRE : 0 (RG-B04).
 *
 * E(c,p) = S + V du client (hors commande en cours de modification),
 * R(r,p) = Q − V(r,p).
 */
final class Availability
{
    public static function compute(?int $limit, int $engaged, int $regionRemaining, bool $limitRequired): int
    {
        if ($limit === null) {
            return $limitRequired ? 0 : max(0, $regionRemaining);
        }

        return max(0, min($limit - $engaged, $regionRemaining));
    }
}
