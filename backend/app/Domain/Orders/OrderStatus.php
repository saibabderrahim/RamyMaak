<?php

namespace App\Domain\Orders;

/**
 * Commande (§6.2). Le panier n'existe que sur l'appareil du client : il n'a
 * pas de statut en base.
 */
enum OrderStatus: string
{
    case Submitted = 'SUBMITTED';
    case Validated = 'VALIDATED';
    case Rejected = 'REJECTED';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';
    case InPreparation = 'IN_PREPARATION';
    case Prepared = 'PREPARED';
    case Shipped = 'SHIPPED';
    case Delivered = 'DELIVERED';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Envoyée',
            self::Validated => 'Validée',
            self::Rejected => 'Rejetée',
            self::Cancelled => 'Annulée',
            self::Expired => 'Expirée',
            self::InPreparation => 'En préparation',
            self::Prepared => 'Préparée',
            self::Shipped => 'Expédiée',
            self::Delivered => 'Livrée',
        };
    }

    /**
     * En attente de décision : quantités demandées comptées dans S(c,p) (§5.1).
     *
     * @return list<self>
     */
    public static function pending(): array
    {
        return [self::Submitted];
    }

    /**
     * Validée et suivantes : quantités validées comptées dans V(c,p) (§5.1).
     *
     * @return list<self>
     */
    public static function validated(): array
    {
        return [self::Validated, self::InPreparation, self::Prepared, self::Shipped, self::Delivered];
    }

    /**
     * Commandes qui comptent pour RG-C04 et pour E(c,p) : ni rejetées, ni
     * annulées, ni expirées.
     *
     * @return list<self>
     */
    public static function counted(): array
    {
        return [...self::pending(), ...self::validated()];
    }

    /** @return list<string> */
    public static function values(array $statuses): array
    {
        return array_map(fn (self $status) => $status->value, $statuses);
    }
}
