<?php

namespace App\Http\Requests\Reasons;

use App\Domain\Reasons\ReasonCodeBook;
use App\Models\ReasonCode;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * Modification d'un motif (EF-ADM-05, I-82 ; contrat du lot 6, §4) :
 * libellé, activation, commentaire exigé, ordre. Le type et le code sont
 * immuables (422 `reasons.code_immutable`) ; il n'y a pas de suppression.
 * Contrôlé avant la validation : droit `settings.manage` (403), puis motif
 * existant (404, `{reasonCode}` numérique par la route, non lié ici pour que
 * le 403 passe avant le 404).
 */
class UpdateReasonCodeRequest extends FormRequest
{
    private ?ReasonCode $resolvedReason = null;

    public function authorize(): Response
    {
        /** @var User $user */
        $user = $this->user();
        if (! $user->can('settings.manage')) {
            return Response::deny();
        }

        $id = $this->route('reasonCode');
        // Identifiant numérique (route) tenant dans un entier de base de données.
        $reason = is_string($id) && ctype_digit($id) && strlen($id) <= 18 ? ReasonCode::query()->find((int) $id) : null;
        if ($reason === null) {
            return Response::denyAsNotFound(__('errors.NOT_FOUND'));
        }
        $this->resolvedReason = $reason;

        return Response::allow();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['prohibited'],
            'code' => ['prohibited'],
            'label' => ['sometimes', 'required', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'requires_comment' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:'.StoreReasonCodeRequest::MAX_SORT_ORDER],
        ];
    }

    /** Motif désigné, contrôlé par authorize(). */
    public function reason(): ReasonCode
    {
        return $this->resolvedReason ?? throw new LogicException('Motif non résolu.');
    }

    /**
     * Champs modifiables transmis.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return $this->safe()->only(ReasonCodeBook::EDITABLE);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.prohibited' => __('reasons.code_immutable'),
            'code.prohibited' => __('reasons.code_immutable'),
            'label.required' => __('reasons.label_format', ['max' => ReasonCode::LABEL_MAX]),
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
