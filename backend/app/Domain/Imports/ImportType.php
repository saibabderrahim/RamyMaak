<?php

namespace App\Domain\Imports;

use App\Domain\Access\Permission;

/**
 * Types de fichiers importables (§8). Les quotas et les bornes s'ajoutent
 * aux lots 3 et 4.
 */
enum ImportType: string
{
    case Clients = 'CLIENTS';
    case Products = 'PRODUCTS';
    case Prices = 'PRICES';

    public function label(): string
    {
        return match ($this) {
            self::Clients => 'Clients',
            self::Products => 'Produits',
            self::Prices => 'Prix',
        };
    }

    /** Droit requis pour déposer et appliquer ce type de fichier. */
    public function permission(): Permission
    {
        return match ($this) {
            self::Clients => Permission::ClientManage,
            self::Products, self::Prices => Permission::ProductManage,
        };
    }
}
