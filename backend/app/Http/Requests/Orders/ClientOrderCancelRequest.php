<?php

namespace App\Http\Requests\Orders;

use App\Domain\Access\Permission;

/**
 * Annulation par le client (§6.3, I-52) : `version` affichée, entier JSON
 * strict. Transition, version, journée et heure limite sont contrôlées par
 * ClientOrders::cancel ; un client bloqué peut annuler (I-53).
 */
class ClientOrderCancelRequest extends ClientOrderRequest
{
    protected function permissions(): array
    {
        return [Permission::OrderCreate];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer:strict', 'min:1'],
        ];
    }

    public function version(): int
    {
        return (int) $this->validated('version');
    }
}
