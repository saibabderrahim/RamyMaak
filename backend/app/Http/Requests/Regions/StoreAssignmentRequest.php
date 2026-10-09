<?php

namespace App\Http\Requests\Regions;

use App\Domain\Referentials\AssignmentKind;
use App\Models\Region;
use App\Models\User;
use App\Support\BusinessClock;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Nouvelle affectation à une région (§3.3, §3.4). Les règles qui dépendent
 * des autres affectations (titulaire unique, doublon) sont vérifiées par le
 * contrôleur, dans la transaction qui crée l'affectation.
 */
class StoreAssignmentRequest extends FormRequest
{
    // Contrôle des droits avant la validation : 403 sans droit, 404 hors périmètre.
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('update', $this->route('region'));
    }

    protected function prepareForValidation(): void
    {
        // Sans date de début, l'affectation commence aujourd'hui (jour métier).
        if (! $this->filled('starts_on')) {
            $this->merge(['starts_on' => BusinessClock::today()->format('Y-m-d')]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'kind' => ['required', Rule::enum(AssignmentKind::class)],
            'starts_on' => ['bail', 'required', 'date_format:Y-m-d', function (string $attribute, mixed $value, Closure $fail): void {
                if ((string) $value < BusinessClock::today()->format('Y-m-d')) {
                    $fail(__('regions.starts_in_past'));
                }
            }],
            // Une suppléance couvre une période bornée (§3.4).
            'ends_on' => ['bail', 'nullable', 'required_if:kind,'.AssignmentKind::Deputy->value, 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'replace' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * L'utilisateur est actif et a le rôle qu'exige la nature de
     * l'affectation : régional pour titulaire et suppléant, ADV pour ADV.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['user_id', 'kind'])) {
                    return;
                }

                $user = User::query()->findOrFail($this->integer('user_id'));
                $kind = AssignmentKind::from($this->string('kind')->value());

                if (! $user->is_active) {
                    $validator->errors()->add('user_id', __('regions.user_inactive'));
                } elseif ($user->appRole() !== $kind->requiredRole()) {
                    $validator->errors()->add('user_id', __('regions.user_role', [
                        'kind' => $kind->label(),
                        'role' => $kind->requiredRole()->label(),
                    ]));
                }
            },
        ];
    }

    public function region(): Region
    {
        /** @var Region */
        return $this->route('region');
    }

    public function kind(): AssignmentKind
    {
        return AssignmentKind::from($this->string('kind')->value());
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('regions.attributes');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ends_on.required_if' => __('regions.deputy_needs_end'),
            'ends_on.after_or_equal' => __('regions.ends_before_start'),
        ];
    }
}
