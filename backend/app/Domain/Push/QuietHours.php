<?php

namespace App\Domain\Push;

use App\Domain\Settings\Settings;
use App\Support\BusinessClock;
use DateTimeInterface;

/**
 * Plage sans push (EF-NOT-02) : paramètres `notifications.quiet_start` et
 * `notifications.quiet_end`, heures « HH:MM » du fuseau métier. Début
 * inclus, fin exclue ; une plage peut passer minuit (21:00 → 06:00). Sans
 * début ou sans fin, ou avec un début égal à la fin, il n'y a pas de plage.
 * Un push qui tombe dans la plage est abandonné, pas reporté (I-105).
 */
final class QuietHours
{
    public function __construct(private readonly Settings $settings) {}

    public function active(?DateTimeInterface $at = null): bool
    {
        $start = self::minutes($this->settings->get('notifications.quiet_start'));
        $end = self::minutes($this->settings->get('notifications.quiet_end'));
        if ($start === null || $end === null || $start === $end) {
            return false;
        }

        $local = BusinessClock::toLocal($at ?? now());
        $minute = $local->hour * 60 + $local->minute;

        return $start < $end
            ? $minute >= $start && $minute < $end
            : $minute >= $start || $minute < $end;
    }

    /** Minutes depuis minuit d'une heure « HH:MM », null si vide ou mal formée. */
    private static function minutes(mixed $time): ?int
    {
        if (! is_string($time) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $match) !== 1) {
            return null;
        }

        return (int) $match[1] * 60 + (int) $match[2];
    }
}
