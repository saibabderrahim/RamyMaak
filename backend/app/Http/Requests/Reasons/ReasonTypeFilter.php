<?php

namespace App\Http\Requests\Reasons;

use App\Domain\Reasons\ReasonType;

/**
 * Types de motifs demandés (`?type=ADJUSTMENT,REJECTION` ou
 * `?type[]=ADJUSTMENT`, `filter[type]` côté Admin), casse indifférente.
 */
final class ReasonTypeFilter
{
    /**
     * Liste de types : texte séparé par des virgules ou liste de textes ;
     * null si un type est inconnu, un élément est vide ou imbriqué.
     *
     * @return list<ReasonType>|null
     */
    public static function parse(mixed $value): ?array
    {
        $values = is_array($value) ? array_values($value) : [$value];
        $types = [];
        foreach ($values as $item) {
            if (! is_string($item)) {
                return null;
            }
            foreach (explode(',', $item) as $part) {
                $type = ReasonType::tryFrom(mb_strtoupper(trim($part)));
                if ($type === null) {
                    return null;
                }
                $types[$type->value] = $type;
            }
        }

        return $types === [] ? null : array_values($types);
    }
}
