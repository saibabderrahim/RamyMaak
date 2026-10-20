<?php

namespace App\Http\Requests\Reasons;

use App\Domain\Access\Role;
use App\Domain\Reasons\ReasonType;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * Motifs actifs pour les listes de choix (EF-ADM-05 ; contrat du lot 6,
 * §2 et §4) : `?type=ADJUSTMENT[,REJECTION]` (tous les types sans filtre).
 * Contrôlé avant la validation (403 avant 422) : rôle Régional, ADV ou
 * Admin ; le client et un compte sans rôle reçoivent 403.
 */
class ListReasonCodesRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User $user */
        $user = $this->user();

        return in_array($user->appRole(), [Role::Regional, Role::Adv, Role::Admin], true);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', function (string $attribute, mixed $value, Closure $fail): void {
                if (ReasonTypeFilter::parse($value) === null) {
                    $fail(__('reasons.unknown_type'));
                }
            }],
        ];
    }

    /**
     * Types demandés, tous sans filtre.
     *
     * @return list<ReasonType>
     */
    public function types(): array
    {
        $value = $this->query('type');

        return $value === null ? ReasonType::cases() : (ReasonTypeFilter::parse($value) ?? throw new LogicException('Types non validés.'));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('reasons.attributes');
    }
}
