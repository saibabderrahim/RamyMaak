<?php

namespace App\Domain\Imports;

/**
 * Cycle de vie d'un import (§8.1) : dépôt → analyse en tâche de fond →
 * rapport (prêt ou en erreur) → application en une seule transaction.
 */
enum ImportStatus: string
{
    case Pending = 'PENDING';
    case Analyzing = 'ANALYZING';
    case Ready = 'READY';
    case Invalid = 'INVALID';
    case Applied = 'APPLIED';
    case Failed = 'FAILED';

    public function isFinal(): bool
    {
        return in_array($this, [self::Applied, self::Failed], true);
    }
}
