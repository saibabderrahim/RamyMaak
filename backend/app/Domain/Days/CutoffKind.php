<?php

namespace App\Domain\Days;

/**
 * Heures limites d'une journée (§4.3) : valeur par défaut dans les paramètres
 * (« cutoffs.* »), copiée sur la journée à son ouverture.
 */
enum CutoffKind: string
{
    case Distribution = 'DISTRIBUTION';
    case Ordering = 'ORDERING';
    case Validation = 'VALIDATION';
    case Closing = 'CLOSING';

    public function label(): string
    {
        return match ($this) {
            self::Distribution => 'Répartition',
            self::Ordering => 'Commande',
            self::Validation => 'Validation',
            self::Closing => 'Clôture',
        };
    }

    /** Clé du paramètre général (EF-ADM-04). */
    public function settingKey(): string
    {
        return 'cutoffs.'.strtolower($this->name);
    }

    /** Colonne de business_days. */
    public function column(): string
    {
        return match ($this) {
            self::Closing => 'closing_at',
            default => strtolower($this->name).'_cutoff_at',
        };
    }

    /** Colonne de prolongation par région (RG-A05), pour les heures qui en admettent. */
    public function overrideColumn(): ?string
    {
        return match ($this) {
            self::Distribution => 'distribution_cutoff_override_at',
            self::Ordering => 'ordering_cutoff_override_at',
            default => null,
        };
    }

    /**
     * Ordre chronologique des heures limites d'une journée.
     *
     * @return list<self>
     */
    public static function chronological(): array
    {
        return [self::Distribution, self::Ordering, self::Validation, self::Closing];
    }
}
