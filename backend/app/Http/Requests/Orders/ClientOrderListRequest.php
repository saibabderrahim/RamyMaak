<?php

namespace App\Http\Requests\Orders;

use App\Domain\Access\Permission;

/**
 * « Mes commandes » (EF-CLT-04) : commandes du client, toutes journées.
 * Filtres `filter[day]` et `filter[status]` contrôlés par le contrôleur
 * (constructeur de requêtes : filtre inconnu ou tri → 422).
 */
class ClientOrderListRequest extends ClientOrderRequest
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
