<?php

namespace App\Http\Requests\Orders;

use App\Domain\Access\Permission;
use App\Domain\Access\Role;

/**
 * Détail d'une commande de la file (EF-ADV-04 ; contrat du lot 6, §5) :
 * `order.view`, ADV ou Admin (lecture).
 */
class AdvOrderShowRequest extends AdvOrderTargetRequest
{
    protected function permission(): Permission
    {
        return Permission::OrderView;
    }

    protected function roles(): array
    {
        return [Role::Adv, Role::Admin];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
