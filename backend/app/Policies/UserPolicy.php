<?php

namespace App\Policies;

use App\Domain\Access\Perimeter;
use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

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
     * son périmètre (EF-AUTH-05, RG-S01) — jamais un compte du personnel. Pour
     * le régional, tout autre compte répond 404 : l'existence d'un compte hors
     * de son périmètre n'est pas révélée (RG-S02).
     */
    public function resetPassword(User $actor, User $target): Response
    {
        if ($actor->can(Permission::UserManage->value)) {
            return Response::allow();
        }

        if (! $actor->can(Permission::UserResetClientPassword->value)) {
            return Response::deny();
        }

        $inPerimeter = $target->hasAppRole(Role::Client)
            && $target->client !== null
            && $this->perimeter->canAccessClient($actor, $target->client);

        return $inPerimeter ? Response::allow() : Response::denyAsNotFound(__('errors.NOT_FOUND'));
    }
}
