<?php

namespace App\Http\Requests\Reasons;

use App\Domain\Reasons\ReasonType;
use App\Models\ReasonCode;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création d'un motif (EF-ADM-05 ; contrat du lot 6, §4) :
 * `{type, code, label, requires_comment?, sort_order?}`. Droit
 * `settings.manage` contrôlé avant la validation (403). Forme seulement :
 * le format du code, son unicité et celle du libellé par type sont
 * contrôlés par ReasonCodeBook (422 sur `code` ou `label`).
 */
class StoreReasonCodeRequest extends FormRequest
{
    /** SMALLINT UNSIGNED. */
    public const MAX_SORT_ORDER = 65535;

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
            'type' => ['required', 'string', Rule::in(ReasonType::values())],
            'code' => ['required', 'string'],
            'label' => ['required', 'string'],
            'requires_comment' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:'.self::MAX_SORT_ORDER],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.in' => __('reasons.unknown_type'),
            'label.required' => __('reasons.label_format', ['max' => ReasonCode::LABEL_MAX]),
            'code.required' => __('reasons.code_format'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('reasons.attributes');
    }
}
