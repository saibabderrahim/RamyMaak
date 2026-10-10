<?php

namespace App\Domain\Days;

/**
 * Statut d'une journée commerciale (§10.2) : ouverte jusqu'à la clôture
 * automatique (§4.4).
 */
enum BusinessDayStatus: string
{
    case Open = 'OPEN';
    case Closed = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Ouverte',
            self::Closed => 'Clôturée',
        };
    }
}
