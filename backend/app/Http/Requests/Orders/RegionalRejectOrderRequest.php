<?php

namespace App\Http\Requests\Orders;

use App\Domain\Orders\OrderDecisions;
use App\Domain\Reasons\ReasonType;
use App\Rules\ActiveReasonCode;

/**
 * Rejet motivé d'une commande envoyée (RG-V05 ; contrat du lot 6, §3) :
 * `version`, `reason_code` d'un motif REJECTION actif, `comment` de 500
 * caractères au plus. Le commentaire exigé par le motif (ex. AUTRE) est
 * contrôlé par OrderDecisions, après les contrôles de transition, de
 * version et de journée.
 */
class RegionalRejectOrderRequest extends RegionalDecisionRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'version' => $this->versionRules(),
            'reason_code' => ['required', 'string', new ActiveReasonCode(ReasonType::Rejection)],
            'comment' => ['nullable', 'string', 'max:'.OrderDecisions::DECISION_COMMENT_MAX],
        ];
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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'comment.max' => __('reasons.comment_max', ['max' => OrderDecisions::DECISION_COMMENT_MAX]),
        ];
    }
}
