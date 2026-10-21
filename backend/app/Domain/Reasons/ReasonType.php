<?php

namespace App\Domain\Reasons;

/**
 * Type d'un motif (§10.3, EF-ADM-05) : donné par la colonne qui porte le
 * code (rejet et annulation dans `orders.decision_reason_code`, ajustement
 * et écart d'expédition sur les lignes).
 */
enum ReasonType: string
{
    case Rejection = 'REJECTION';
    case Adjustment = 'ADJUSTMENT';
    case ShippingGap = 'SHIPPING_GAP';
    case Cancellation = 'CANCELLATION';

    public function label(): string
    {
        return match ($this) {
            self::Rejection => 'Rejet',
            self::Adjustment => 'Ajustement',
            self::ShippingGap => 'Écart d\'expédition',
            self::Cancellation => 'Annulation',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
