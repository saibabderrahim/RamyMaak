<?php

namespace App\Domain\Imports;

use App\Domain\Access\Permission;

/**
 * Types de fichiers importables (§8). Les quotas (lot 3) portent sur une
 * journée et un mode ; les bornes s'ajoutent au lot 4.
 */
enum ImportType: string
{
    case Clients = 'CLIENTS';
    case Products = 'PRODUCTS';
    case Prices = 'PRICES';
    case Quotas = 'QUOTAS';

    public function label(): string
    {
        return match ($this) {
            self::Clients => 'Clients',
            self::Products => 'Produits',
            self::Prices => 'Prix',
            self::Quotas => 'Quotas',
        };
    }

    /** Droit requis pour déposer et appliquer ce type de fichier. */
    public function permission(): Permission
    {
        return match ($this) {
            self::Clients => Permission::ClientManage,
            self::Products, self::Prices => Permission::ProductManage,
            self::Quotas => Permission::QuotaEdit,
        };
    }

    /** Import rattaché à une journée, avec un mode Remplacer / Compléter (§8.1). */
    public function isDaily(): bool
    {
        return $this === self::Quotas;
    }
}
