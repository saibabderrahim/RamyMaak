<?php

namespace App\Support;

/**
 * Montants en dinars (RG-P04) calculés en centimes entiers : aucun écart
 * d'arrondi entre la fiche produit, le panier et la commande. TVA en points
 * de base (19,00 % → 1 900), arrondie au centime, demi supérieur.
 * La PWA applique les mêmes règles (shared/money.ts).
 */
final class Money
{
    /** Montant DECIMAL lu en base (chaîne ou nombre selon le moteur) → centimes. */
    public static function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    public static function amount(int $cents): float
    {
        return $cents / 100;
    }

    /** Taux en pourcentage (« 19.00 ») → points de base (1 900). */
    public static function basisPoints(mixed $rate): int
    {
        return (int) round((float) $rate * 100);
    }

    /** TVA d'un montant HT, arrondie au centime (demi supérieur). */
    public static function vatCents(int $htCents, int $basisPoints): int
    {
        return intdiv($htCents * $basisPoints + 5000, 10000);
    }

    /** TTC = HT + TVA. */
    public static function ttcCents(int $htCents, int $basisPoints): int
    {
        return $htCents + self::vatCents($htCents, $basisPoints);
    }

    /** Prix HT d'une unité de vente au détail (UV / unités par UV), au centime le plus proche. */
    public static function unitCents(int $cents, int $units): int
    {
        $units = max(1, $units);

        return intdiv(2 * $cents + $units, 2 * $units);
    }
}
