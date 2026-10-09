<?php

namespace App\Policies;

use App\Domain\Access\Permission;
use App\Domain\Imports\ImportType;
use App\Models\Import;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Droits sur les imports Excel des référentiels (§3.2, §8.3) : déposer,
 * appliquer et télécharger un gabarit exigent le droit du type (CLIENTS →
 * client.manage, PRODUCTS et PRICES → product.manage) ; un import se lit par
 * son auteur ou par l'Admin (user.manage). L'import d'un autre répond 404 :
 * on ne révèle pas son existence.
 */
class ImportPolicy
{
    /** Historique : réservé à qui peut importer, ou à l'Admin. */
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::UserManage->value) || $this->canImportSomething($actor);
    }

    public function view(User $actor, Import $import): Response
    {
        return $this->ownsOrAdministers($actor, $import)
            ? Response::allow()
            : Response::denyAsNotFound(__('errors.NOT_FOUND'));
    }

    /** Dépôt d'un fichier : au moins un type autorisé (le type est contrôlé ensuite). */
    public function import(User $actor): bool
    {
        return $this->canImportSomething($actor);
    }

    /** Dépôt d'un fichier du type donné, ou téléchargement de son gabarit. */
    public function create(User $actor, ImportType $type): bool
    {
        return $actor->can($type->permission()->value);
    }

    /** Application : l'auteur ou l'Admin, avec le droit du type. */
    public function apply(User $actor, Import $import): Response
    {
        $visible = $this->view($actor, $import);
        if ($visible->denied()) {
            return $visible;
        }

        return $actor->can($import->type->permission()->value) ? Response::allow() : Response::deny();
    }

    private function ownsOrAdministers(User $actor, Import $import): bool
    {
        return $import->user_id === $actor->getKey() || $actor->can(Permission::UserManage->value);
    }

    private function canImportSomething(User $actor): bool
    {
        foreach (ImportType::cases() as $type) {
            if ($actor->can($type->permission()->value)) {
                return true;
            }
        }

        return false;
    }
}
