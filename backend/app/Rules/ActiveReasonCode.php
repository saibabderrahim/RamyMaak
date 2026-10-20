<?php

namespace App\Rules;

use App\Domain\Reasons\ReasonCodes;
use App\Domain\Reasons\ReasonType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Règle de validation : code d'un motif **actif** du type donné (EF-ADM-05),
 * ex. `['nullable', 'string', new ActiveReasonCode(ReasonType::Adjustment)]`.
 * Lit les motifs par ReasonCodes (une requête par requête HTTP).
 */
final class ActiveReasonCode implements ValidationRule
{
    public function __construct(private readonly ReasonType $type) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! app(ReasonCodes::class)->isActive($this->type, $value)) {
            $fail(__('reasons.unknown'));
        }
    }
}
