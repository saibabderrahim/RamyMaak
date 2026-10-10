<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Valeur d'un filtre de liste (`filter[nom]`) : une valeur simple ou une
 * liste de valeurs simples. Un tableau imbriqué (`filter[nom][0][]=…`) est
 * refusé en 422, jamais converti en texte (§11.4 : filtre invalide → 422).
 */
final class FilterValue
{
    /**
     * Texte recherché : une virgule découpée en liste par le constructeur de
     * requêtes est recollée.
     */
    public static function text(mixed $value, string $filter): string
    {
        return trim(implode(',', self::scalars($value, $filter)));
    }

    /**
     * @return list<string>
     */
    public static function scalars(mixed $value, string $filter): array
    {
        $values = is_array($value) ? array_values($value) : [$value];

        foreach ($values as $item) {
            if ($item !== null && ! is_scalar($item)) {
                throw ValidationException::withMessages(["filter.{$filter}" => [__('validation.invalid_filter')]]);
            }
        }

        return array_map(fn (mixed $item) => (string) $item, $values);
    }
}
