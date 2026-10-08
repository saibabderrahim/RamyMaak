<?php

namespace App\Models;

use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * Entrée du journal d'audit. Le journal n'est pas modifiable depuis
 * l'application (§12) : seule la purge de rétention, qui passe par une
 * requête de masse, peut supprimer des entrées.
 */
class AuditLog extends Activity
{
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Le journal d\'audit n\'est pas modifiable.');
        });

        static::deleting(function (): never {
            throw new LogicException('Le journal d\'audit n\'est pas modifiable.');
        });
    }
}
