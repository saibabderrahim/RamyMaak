<?php

namespace App\Http\Requests\Reasons;

use App\Domain\Reasons\ReasonType;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * Liste des motifs de l'Admin, désactivés compris (EF-ADM-05 ; contrat du
 * lot 6, §4) : `?filter[type]=` (un ou plusieurs types séparés par des
 * virgules). Droit `settings.manage` contrôlé avant la validation (403),
 * autre filtre ou type inconnu → 422.
 */
class ListAdminReasonCodesRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User $user */
        $user = $this->user();

        return $user->can('settings.manage');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['nullable', 'array:type'],
            'filter.type' => ['nullable', function (string $attribute, mixed $value, Closure $fail): void {
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
        $filter = $this->query('filter');
        $value = is_array($filter) ? ($filter['type'] ?? null) : null;

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
