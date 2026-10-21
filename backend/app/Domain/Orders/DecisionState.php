<?php

namespace App\Domain\Orders;

use App\Http\Problems\ErrorCode;

/**
 * Décision possible sur une commande pour un régional (contrat du lot 6,
 * §3) : `can_validate`, `can_reject`, raison du blocage de la validation
 * (CLIENT_BLOCKED, CUTOFF_PASSED, DAY_NOT_OPEN) et fenêtre de validation.
 */
final readonly class DecisionState
{
    public function __construct(
        public ValidationState $validation,
        public bool $canValidate,
        public bool $canReject,
        public ?ErrorCode $blockedReason,
    ) {}

    /**
     * @return array{validation: array{cutoff: string, cutoff_at: string, extended: bool, blocking: bool, passed: bool}, can_validate: bool, can_reject: bool, blocked_reason: ?string}
     */
    public function toArray(): array
    {
        return [
            'validation' => $this->validation->toArray(),
            'can_validate' => $this->canValidate,
            'can_reject' => $this->canReject,
            'blocked_reason' => $this->blockedReason?->value,
        ];
    }
}
