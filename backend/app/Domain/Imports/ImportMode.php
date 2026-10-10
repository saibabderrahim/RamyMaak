<?php

namespace App\Domain\Imports;

/**
 * Mode d'un import de quotas (§8.1) : Remplacer (une cellule vide vaut 0) ou
 * Compléter (une cellule vide ne change rien).
 */
enum ImportMode: string
{
    case Replace = 'REPLACE';
    case Complete = 'COMPLETE';

    public function label(): string
    {
        return match ($this) {
            self::Replace => 'Remplacer',
            self::Complete => 'Compléter',
        };
    }
}
