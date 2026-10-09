<?php

namespace App\Policies;

use App\Domain\Access\Perimeter;
use App\Domain\Access\Permission;
use App\Models\Region;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Droits sur les régions, leurs affectations et les dépôts (§3.2, ligne
 * « Régions et affectations ») : lecture avec `region.view` dans le périmètre
 * (RG-S02), écriture avec `region.manage`. Une région hors périmètre répond
 * 404 pour ne pas révéler son existence ; une région inactive n'est visible
 * que des gestionnaires.
 */
class RegionPolicy
{
    public function __construct(private readonly Perimeter $perimeter) {}

    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::RegionView->value);
    }

    /** Fiche et historique des affectations. */
    public function view(User $actor, Region $region): Response
    {
        if (! $actor->can(Permission::RegionView->value)) {
            return Response::deny();
        }

        return $this->visible($actor, $region) ? Response::allow() : Response::denyAsNotFound(__('errors.NOT_FOUND'));
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::RegionManage->value);
    }

    /** Modification de la région, création et fin de ses affectations. */
    public function update(User $actor, Region $region): Response
    {
        if (! $actor->can(Permission::RegionManage->value)) {
            return Response::deny();
        }

        return $this->visible($actor, $region) ? Response::allow() : Response::denyAsNotFound(__('errors.NOT_FOUND'));
    }

    public function manageDepots(User $actor): bool
    {
        return $actor->can(Permission::RegionManage->value);
    }

    /** Les régions inactives restent visibles de ceux qui peuvent les réactiver. */
    public function viewInactive(User $actor): bool
    {
        return $actor->can(Permission::RegionManage->value);
    }

    private function visible(User $actor, Region $region): bool
    {
        return ($region->is_active || $this->viewInactive($actor))
            && $this->perimeter->canAccessRegion($actor, $region->id);
    }
}
