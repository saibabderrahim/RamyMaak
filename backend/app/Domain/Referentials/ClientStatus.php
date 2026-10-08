<?php

namespace App\Domain\Referentials;

/**
 * Statut d'un client (§3.5).
 */
enum ClientStatus: string
{
    /** Peut commander. */
    case Active = 'ACTIVE';

    /** Peut se connecter et consulter, ne peut pas commander. */
    case Blocked = 'BLOCKED';

    /** Archivé : hors périmètre, comptes désactivés. */
    case Inactive = 'INACTIVE';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Actif',
            self::Blocked => 'Bloqué',
            self::Inactive => 'Inactif',
        };
    }

    /** Valeur de la colonne « statut » des fichiers Excel (Annexe B.3). */
    public static function fromSpreadsheet(string $value): ?self
    {
        return match (mb_strtoupper(trim($value))) {
            'ACTIF', 'ACTIVE' => self::Active,
            'BLOQUE', 'BLOQUÉ', 'BLOCKED' => self::Blocked,
            'INACTIF', 'INACTIVE' => self::Inactive,
            default => null,
        };
    }
}
