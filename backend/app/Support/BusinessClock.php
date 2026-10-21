<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Heure métier (H-10) : l'heure du serveur fait foi, exprimée dans le fuseau
 * Africa/Algiers ; le stockage reste en UTC (§9.4).
 */
final class BusinessClock
{
    public static function timezone(): string
    {
        return (string) config('ramymaak.business_timezone');
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }

    /**
     * Instant d'une heure « HH:MM » de la journée donnée, dans le fuseau
     * métier ; à convertir en UTC (->utc()) avant tout enregistrement.
     */
    public static function at(DateTimeInterface $day, string $time): CarbonImmutable
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time, 2));

        return CarbonImmutable::createFromFormat('!Y-m-d', $day->format('Y-m-d'), self::timezone())
            ->setTime($hours, $minutes);
    }

    public static function toLocal(DateTimeInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone(self::timezone());
    }

    /**
     * Début de la journée « AAAA-MM-JJ » dans le fuseau métier, ou null si la
     * date est invalide.
     */
    public static function parseDay(string $day): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $day, self::timezone());

        return $parsed !== false && $parsed->format('Y-m-d') === $day ? $parsed : null;
    }
}
