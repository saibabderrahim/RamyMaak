<?php

namespace App\Policies;

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Droits sur le catalogue (§3.2, ligne « Référentiels — Produits, photos,
 * prix ») : lecture avec product.view, le client ne voyant que les produits
 * actifs (un produit inactif n'existe pas pour lui : 404) ; écriture des
 * fiches, familles, photos et prix avec product.manage (Admin, ADV en
 * option). L'historique des prix est réservé au personnel.
 */
class ProductPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::ProductView->value);
    }

    public function view(User $actor, Product $product): Response
    {
        if (! $actor->can(Permission::ProductView->value)) {
            return Response::deny();
        }

        if (! $product->is_active && self::seesActiveCatalogOnly($actor)) {
            return Response::denyAsNotFound(__('errors.NOT_FOUND'));
        }

        return Response::allow();
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::ProductManage->value);
    }

    /** Fiche, photos et nouveaux prix. */
    public function update(User $actor, Product $product): bool
    {
        return $actor->can(Permission::ProductManage->value);
    }

    /** Historique des prix (EF-PRD-03) : personnel seulement. */
    public function viewPrices(User $actor, Product $product): bool
    {
        return $actor->can(Permission::ProductView->value) && ! $actor->hasAppRole(Role::Client);
    }

    /** Création et modification des familles et gammes. */
    public function manageFamilies(User $actor): bool
    {
        return $actor->can(Permission::ProductManage->value);
    }

    /** Un compte client ne reçoit que le catalogue actif (pas de filtre sur le statut). */
    public static function seesActiveCatalogOnly(User $actor): bool
    {
        return $actor->hasAppRole(Role::Client);
    }
}
