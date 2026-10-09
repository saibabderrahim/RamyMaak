<?php

namespace App\Policies;

use App\Domain\Access\Perimeter;
use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Models\Client;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Droits sur le référentiel clients (§3.2, ligne « Référentiels — Clients ») :
 * lecture avec client.view dans le périmètre (RG-S02), un compte client lit
 * sa fiche ; écriture avec client.manage ; statut avec client.block en plus
 * (EF-CLI-04). Un client hors périmètre répond 404 : on ne révèle pas son
 * existence.
 */
class ClientPolicy
{
    public function __construct(private readonly Perimeter $perimeter) {}

    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::ClientView->value);
    }

    public function view(User $actor, Client $client): Response
    {
        if (! $actor->can(Permission::ClientView->value) && ! $actor->hasAppRole(Role::Client)) {
            return Response::deny();
        }

        return $this->withinPerimeter($actor, $client);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::ClientManage->value);
    }

    public function update(User $actor, Client $client): Response
    {
        if (! $actor->can(Permission::ClientManage->value)) {
            return Response::deny();
        }

        return $this->withinPerimeter($actor, $client);
    }

    /** Blocage, déblocage, mise en inactivité (EF-CLI-04), en plus de update(). */
    public function changeStatus(User $actor, Client $client): bool
    {
        return $actor->can(Permission::ClientBlock->value);
    }

    /** Catégories : lisibles par le personnel qui consulte les clients. */
    public function viewCategories(User $actor): bool
    {
        return $actor->can(Permission::ClientView->value);
    }

    public function manageCategories(User $actor): bool
    {
        return $actor->can(Permission::ClientManage->value);
    }

    private function withinPerimeter(User $actor, Client $client): Response
    {
        return $this->perimeter->canAccessClient($actor, $client)
            ? Response::allow()
            : Response::denyAsNotFound(__('errors.NOT_FOUND'));
    }
}
