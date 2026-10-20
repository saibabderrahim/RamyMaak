<?php

namespace App\Http\Requests\Orders;

use App\Domain\Access\Permission;

/**
 * Bon de commande d'une commande de la file (RG-A04 ; contrat du lot 6, §6,
 * I-86, I-87) : `order.process`, ADV seulement (l'Admin n'imprime pas), dans
 * le périmètre de la commande ; une commande annulée s'imprime aussi.
 */
class AdvOrderPdfRequest extends AdvOrderTargetRequest
{
    protected function permission(): Permission
    {
        return Permission::OrderProcess;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
