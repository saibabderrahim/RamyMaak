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
    case OrderSubmitted = 'ORDER_SUBMITTED';
    case OrderChanged = 'ORDER_CHANGED';
    case OrderDecided = 'ORDER_DECIDED';
    case OrderExpired = 'ORDER_EXPIRED';

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
            self::OrderSubmitted => 'N-05',
            // Commande modifiée ou annulée par le client.
            self::OrderChanged => 'N-06',
            // Validée, ajustée, rejetée ou annulée (décisions du lot 6).
            self::OrderDecided => 'N-07',
            self::OrderExpired => 'N-14',
        };
    }
}
