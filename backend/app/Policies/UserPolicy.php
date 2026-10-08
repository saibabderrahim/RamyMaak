<?php

namespace App\Policies;

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Models\User;

/**
 * Droits sur les comptes (§3.2, ligne « Comptes »).
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::UserManage->value);
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->can(Permission::UserManage->value);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::UserManage->value);
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->can(Permission::UserManage->value);
    }

    /**
     * Admin : tout compte. Régional : comptes clients de son périmètre
     * (EF-AUTH-05). Le périmètre (RG-S01) repose sur les affectations aux
     * régions, introduites au lot 2 ; d'ici là, aucun compte n'y entre.
     */
    public function resetPassword(User $actor, User $target): bool
    {
        if ($actor->can(Permission::UserManage->value)) {
            return true;
        }

        return $actor->can(Permission::UserResetClientPassword->value)
            && $target->hasAppRole(Role::Client)
            && $this->clientInPerimeter($actor, $target);
    }

    private function clientInPerimeter(User $actor, User $target): bool
    {
        return false;
    }
}
