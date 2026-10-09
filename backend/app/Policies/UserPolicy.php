<?php

namespace App\Policies;

use App\Domain\Access\Perimeter;
use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Models\User;

/**
 * Droits sur les comptes (§3.2, ligne « Comptes »).
 */
class UserPolicy
{
    public function __construct(private readonly Perimeter $perimeter) {}

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
     * Admin : tout compte. Régional : comptes clients dont le client est dans
     * son périmètre (EF-AUTH-05, RG-S01) — jamais un compte du personnel.
     */
    public function resetPassword(User $actor, User $target): bool
    {
        if ($actor->can(Permission::UserManage->value)) {
            return true;
        }

        return $actor->can(Permission::UserResetClientPassword->value)
            && $target->hasAppRole(Role::Client)
            && $target->client !== null
            && $this->perimeter->canAccessClient($actor, $target->client);
    }
}
