<?php

namespace App\Http\Requests\Quotas;

use App\Models\RegionQuota;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Publication des quotas (RG-Q04) : une sélection de régions (`region_ids`)
 * ou toutes les régions du périmètre (`all: true`), exactement l'un des deux.
 */
class PublishQuotasRequest extends FormRequest
{
    // Contrôle des droits avant la validation : sans quota.publish, 403 et non 422.
    public function authorize(): bool
    {
        return $this->user()->can('publish', RegionQuota::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'all' => ['sometimes', 'boolean'],
            'region_ids' => ['sometimes', 'nullable', 'array', 'list', 'min:1'],
            'region_ids.*' => ['integer'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['all', 'region_ids'])) {
                return;
            }

            $all = $this->boolean('all');
            $selection = $this->input('region_ids') !== null;
            if ($all && $selection) {
                $validator->errors()->add('region_ids', __('quotas.publish_both'));
            } elseif (! $all && ! $selection) {
                $validator->errors()->add('region_ids', __('quotas.publish_choice'));
            }
        }];
    }

    /**
     * @return list<int>|null null : toutes les régions du périmètre
     */
    public function regionIds(): ?array
    {
        if ($this->boolean('all')) {
            return null;
        }

        return array_values(array_unique(array_map('intval', $this->validated('region_ids'))));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('quotas.attributes');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['list' => __('validation.array')];
    }
}
