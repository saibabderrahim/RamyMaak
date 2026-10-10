<?php

namespace App\Casts;

use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Date calendaire sans heure (journées, dates d'effet, périodes) : stockée
 * « AAAA-MM-JJ » quel que soit le moteur, lue comme le début de ce jour
 * dans le fuseau métier (H-10).
 *
 * @implements CastsAttributes<CarbonImmutable, CarbonImmutable|DateTimeInterface|string|null>
 */
class BusinessDate implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $day = CarbonImmutable::createFromFormat('!Y-m-d', substr((string) $value, 0, 10), BusinessClock::timezone());

        // En JSON (journal d'audit, réponses) : la date du jour « AAAA-MM-JJ »,
        // pas l'instant UTC de minuit à Alger, qui tombe la veille à 23:00.
        return $day === false ? null : $day->settings(['toJsonFormat' => 'Y-m-d']);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $day = BusinessClock::parseDay(substr((string) $value, 0, 10));
        if ($day === null) {
            throw new InvalidArgumentException("Date invalide pour {$key} : {$value}");
        }

        return $day->format('Y-m-d');
    }
}
