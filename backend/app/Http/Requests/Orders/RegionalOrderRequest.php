<?php

namespace App\Http\Requests\Orders;

use App\Domain\Access\Perimeter;
use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Commandes reçues par le régional, en lecture (EF-REG-04 ; contrat du
 * lot 5, §2 et §4). Contrôlés avant la validation (403 avant 422) : rôle
 * Régional et permission order.view ; l'ADV, l'Admin et le client reçoivent
 * 403 (l'ADV et l'Admin lisent les commandes au lot 6). Vient ensuite la
 * cible de la requête (région filtrée, commande désignée), qui doit être
 * dans le périmètre du régional (titulaire ou suppléant) à la date de la
 * journée concernée ; sinon 404, toujours avant la validation.
 */
abstract class RegionalOrderRequest extends FormRequest
{
    public function authorize(): Response
    {
        /** @var User $user */
        $user = $this->user();
        if (! $user->hasAppRole(Role::Regional) || ! $user->can(Permission::OrderView->value)) {
            return Response::deny();
        }

        return $this->authorizeTarget($user);
    }

    /** Région ou commande désignée : 404 hors périmètre, après le contrôle des droits. */
    abstract protected function authorizeTarget(User $user): Response;

    protected function perimeter(): Perimeter
    {
        return app(Perimeter::class);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'day' => 'journée',
            'filter' => 'filtre',
            'filter.status' => 'statut',
            'filter.region' => 'région',
            'sort' => 'tri',
        ];
    }
}
