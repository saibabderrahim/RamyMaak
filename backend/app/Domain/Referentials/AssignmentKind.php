<?php

namespace App\Domain\Referentials;

use App\Domain\Access\Role;

/**
 * Nature d'une affectation à une région (§3.3, §3.4, RG-S05).
 */
enum AssignmentKind: string
{
    case Titular = 'TITULAR';
    case Deputy = 'DEPUTY';
    case Adv = 'ADV';

    public function label(): string
    {
        return match ($this) {
            self::Titular => 'Titulaire',
            self::Deputy => 'Suppléant',
            self::Adv => 'ADV',
        };
    }

    /** Rôle que doit avoir l'utilisateur affecté. */
    public function requiredRole(): Role
    {
        return $this === self::Adv ? Role::Adv : Role::Regional;
    }

    /** Titulaires et suppléants ont les mêmes droits sur la région (§3.4). */
    public function grantsRegionalPerimeter(): bool
    {
        return $this !== self::Adv;
    }
}
