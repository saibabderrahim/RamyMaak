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
    case OrderShipped = 'ORDER_SHIPPED';
    case OrderExpired = 'ORDER_EXPIRED';
    // Rappels et alerte du planificateur (lot 8, §3 du contrat).
    case DistributionReminder = 'DISTRIBUTION_REMINDER';
    case OrderingReminder = 'ORDERING_REMINDER';
    case ValidationReminder = 'VALIDATION_REMINDER';
    case DistributionLate = 'DISTRIBUTION_LATE';
    // Sécurité du compte (lot 8, I-111).
    case AccountSecurity = 'ACCOUNT_SECURITY';

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
            // Commande expédiée par l'ADV (lot 6).
            self::OrderShipped => 'N-09',
            self::OrderExpired => 'N-14',
            self::DistributionReminder => 'N-10',
            self::OrderingReminder => 'N-11',
            self::ValidationReminder => 'N-12',
            self::DistributionLate => 'N-13',
            self::AccountSecurity => 'N-15',
        };
    }

    /**
     * Part aussi en push (§7.8, contrat du lot 8 §2.3) ; les autres restent
     * dans le centre de notifications.
     */
    public function pushable(): bool
    {
        return ! in_array($this, [self::OrderChanged, self::DistributionLate, self::AccountSecurity], true);
    }

    /** Rappel non critique, que l'utilisateur peut couper (EF-NOT-04). */
    public function isReminder(): bool
    {
        return in_array($this, [self::DistributionReminder, self::OrderingReminder, self::ValidationReminder], true);
    }

    /** Groupe d'anti-saturation des push (EF-NOT-02) : un par type. */
    public function pushGroup(): string
    {
        return $this->value;
    }
}
