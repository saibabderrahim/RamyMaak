<?php

namespace App\Domain\Documents;

use App\Support\BusinessClock;
use Carbon\CarbonInterface;

/**
 * Formats des documents (ENF-13, RG-P05) : dates JJ/MM/AAAA, heure
 * d'Alger, montants en DA à deux décimales avec une espace insécable
 * comme séparateur de milliers, virgule décimale.
 */
final class Formats
{
    /** Espace insécable : ne se coupe pas en fin de ligne dans un PDF. */
    public const NBSP = "\u{00A0}";

    /** Mention des montants (RG-P05), reprise par chaque document chiffré. */
    public static function amountNotice(): string
    {
        return (string) __('documents.amount_notice');
    }

    /** « 12 345,60 DA » (montant décimal lu en base ou calculé en dinars). */
    public static function amount(mixed $value): string
    {
        return self::decimal($value).self::NBSP.'DA';
    }

    /** « 12 345,60 » : deux décimales par défaut, séparateur de milliers insécable. */
    public static function decimal(mixed $value, int $decimals = 2): string
    {
        return number_format((float) $value, $decimals, ',', self::NBSP);
    }

    /** « 12 345 » */
    public static function integer(int $value): string
    {
        return number_format($value, 0, ',', self::NBSP);
    }

    /** « 19 » ou « 9,5 » : taux de TVA sans zéros inutiles. */
    public static function rate(mixed $value): string
    {
        $text = number_format((float) $value, 2, ',', '');

        return str_contains($text, ',') ? rtrim(rtrim($text, '0'), ',') : $text;
    }

    /** Journée ou date de livraison (date calendaire, sans fuseau) : « 08/10/2026 », « — » sans date. */
    public static function date(?CarbonInterface $date): string
    {
        return $date === null ? '—' : $date->format('d/m/Y');
    }

    /** Instant exprimé en heure d'Alger : « 08/10/2026 14:05 », « — » sans date. */
    public static function dateTime(?CarbonInterface $moment): string
    {
        return $moment === null ? '—' : BusinessClock::toLocal($moment)->format('d/m/Y H:i');
    }

    /** Équivalent en palettes d'un reste de division : « 2,71 ». */
    public static function pallets(float $value): string
    {
        return self::decimal($value);
    }
}
