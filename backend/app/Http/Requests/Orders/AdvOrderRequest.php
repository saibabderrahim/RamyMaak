<?php

namespace App\Http\Requests\Orders;

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Requêtes de la file ADV (EF-ADV-04 ; contrat du lot 6, §2). Contrôlés
 * avant la validation (403 avant 422) : le rôle et la permission du point
 * d'accès ; vient ensuite la cible (commande désignée, région ou dépôt
 * filtré) qui doit être dans le périmètre de l'appelant (RG-S05) ; sinon 404.
 *
 * Lecture : ADV et Admin avec `order.view`. Écritures (statuts, expédition,
 * annulation, bon de commande) : ADV seulement, avec `order.process` ou
 * `order.cancel-validated` ; l'Admin lit sans écrire (I-87).
 */
abstract class AdvOrderRequest extends FormRequest
{
    /** Permission exigée par le point d'accès. */
    abstract protected function permission(): Permission;

    /**
     * Rôles admis : l'ADV écrit, l'Admin lit.
     *
     * @return list<Role>
     */
    protected function roles(): array
    {
        return [Role::Adv];
    }

    public function authorize(): Response
    {
        /** @var User $user */
        $user = $this->user();
        $role = $user->appRole();
        if ($role === null || ! in_array($role, $this->roles(), true) || ! $user->can($this->permission()->value)) {
            return Response::deny();
        }

        return $this->authorizeTarget($user);
    }

    /** Cible de la requête : 404 hors périmètre, après le contrôle des droits. */
    protected function authorizeTarget(User $user): Response
    {
        return Response::allow();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('orders.adv.attributes');
    }
}
