<?php

namespace App\Http\Requests\Orders;

use App\Domain\Access\Permission;

/** Détail d'une commande du client (EF-CLT-04) : `{order}`, ULID. */
class ClientOrderShowRequest extends ClientOrderRequest
{
    protected function permissions(): array
    {
        return [Permission::OrderView];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
