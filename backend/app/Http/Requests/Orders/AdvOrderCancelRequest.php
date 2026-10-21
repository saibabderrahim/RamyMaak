<?php

namespace App\Http\Requests\Orders;

use App\Domain\Access\Permission;
use App\Domain\Orders\AdvOrders;
use App\Domain\Reasons\ReasonType;
use App\Rules\ActiveReasonCode;

/**
 * Annulation exceptionnelle d'une commande validée (RG-V07, §6.3 ; contrat
 * du lot 6, §5) : `order.cancel-validated`, ADV. Corps : `version` (entier
 * JSON strict), `reason_code` (motif CANCELLATION actif), `comment` (500
 * caractères au plus, obligatoire si le motif l'exige : contrôlé par
 * AdvOrders::cancel). Statut et version sont contrôlés par le service.
 */
class AdvOrderCancelRequest extends AdvOrderTargetRequest
{
    protected function permission(): Permission
    {
        return Permission::OrderCancelValidated;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer:strict', 'min:1'],
            'reason_code' => ['required', 'string', new ActiveReasonCode(ReasonType::Cancellation)],
            'comment' => ['nullable', 'string', 'max:'.AdvOrders::DECISION_COMMENT_MAX],
        ];
    }

    public function version(): int
    {
        return (int) $this->validated('version');
    }

    public function reasonCode(): string
    {
        return (string) $this->validated('reason_code');
    }

    public function comment(): ?string
    {
        $comment = $this->validated('comment');

        return is_string($comment) ? $comment : null;
    }
}
