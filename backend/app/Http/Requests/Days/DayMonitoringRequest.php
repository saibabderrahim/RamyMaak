<?php

namespace App\Http\Requests\Days;

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Suivi de la journée (EF-ADV-03 ; contrat du lot 6, §2 et §6) :
 * `dashboard.view`, ADV ou Admin. La journée est liée par la route (404 si
 * elle n'est pas ouverte) ; les régions lues sont celles du périmètre de
 * l'appelant à la date de la journée (RG-S05), le régional et le client
 * reçoivent 403.
 */
class DayMonitoringRequest extends FormRequest
{
    public function authorize(): Response
    {
        /** @var User $user */
        $user = $this->user();
        $role = $user->appRole();
        if (($role !== Role::Adv && $role !== Role::Admin) || ! $user->can(Permission::DashboardView->value)) {
            return Response::deny();
        }

        return Response::allow();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
