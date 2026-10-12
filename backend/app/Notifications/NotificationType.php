<?php

namespace App\Notifications;

/**
 * Types de notifications du centre de notifications (§7.8, EF-NOT-01) :
 * enregistrés dans la colonne `type` et dans les données de chaque
 * notification, avec le code de la spécification quand il existe.
 */
enum NotificationType: string
{
    case QuotasPublished = 'QUOTAS_PUBLISHED';
    case QuotasUpdated = 'QUOTAS_UPDATED';
    case CutoffExtended = 'CUTOFF_EXTENDED';
    case DistributionPublished = 'DISTRIBUTION_PUBLISHED';
    case LimitsUpdated = 'LIMITS_UPDATED';

    /** Code de la spécification (§7.8), null pour une notification sans code. */
    public function code(): ?string
    {
        return match ($this) {
            self::QuotasPublished => 'N-01',
            self::QuotasUpdated => 'N-02',
            // RG-A05 : « les acteurs concernés sont notifiés », sans code N-xx.
            self::CutoffExtended => null,
            self::DistributionPublished => 'N-03',
            self::LimitsUpdated => 'N-04',
        };
    }
}
