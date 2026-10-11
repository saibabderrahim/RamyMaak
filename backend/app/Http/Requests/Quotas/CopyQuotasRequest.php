<?php

namespace App\Http\Requests\Quotas;

use App\Models\BusinessDay;
use App\Models\RegionQuota;
use App\Support\BusinessClock;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * « Copier depuis la journée… » (EF-ADV-02) : quotas publiés d'une autre
 * journée, ouverte ou clôturée, repris en brouillon. Remplacer : chaque
 * cellule prend la valeur source ; Compléter : seules les cellules vides.
 */
class CopyQuotasRequest extends FormRequest
{
    public const REPLACE = 'REPLACE';

    public const FILL = 'FILL';

    // Contrôle des droits avant la validation : sans quota.edit, 403 et non 422.
    public function authorize(): bool
    {
        return $this->user()->can('edit', RegionQuota::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'source_day' => ['bail', 'required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                $day = BusinessClock::parseDay($value);
                if ($day === null) {
                    $fail(__('quotas.invalid_day'));
                } elseif ($value === $this->targetDay()->dayKey()) {
                    $fail(__('quotas.source_day_same'));
                } elseif (BusinessDay::forDay($value) === null) {
                    $fail(__('quotas.source_day_unknown', ['day' => $day->format('d/m/Y')]));
                }
            }],
            'mode' => ['required', 'string', Rule::in([self::REPLACE, self::FILL])],
            // Absent ou null : toutes les régions du périmètre ; une liste vide n'a pas de sens.
            'region_ids' => ['sometimes', 'nullable', 'array', 'list', 'min:1'],
            'region_ids.*' => ['integer'],
        ];
    }

    public function targetDay(): BusinessDay
    {
        /** @var BusinessDay */
        return $this->route('businessDay');
    }

    public function sourceDay(): BusinessDay
    {
        /** @var BusinessDay */
        return BusinessDay::forDay($this->validated('source_day'));
    }

    public function replaces(): bool
    {
        return $this->validated('mode') === self::REPLACE;
    }

    /**
     * @return list<int>|null null : toutes les régions du périmètre
     */
    public function regionIds(): ?array
    {
        $ids = $this->validated('region_ids');

        return $ids === null ? null : array_values(array_unique(array_map('intval', $ids)));
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
