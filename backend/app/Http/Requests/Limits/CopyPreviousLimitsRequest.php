<?php

namespace App\Http\Requests\Limits;

use App\Domain\Access\Permission;
use Illuminate\Validation\Rule;

/**
 * « Reprendre J-1 » (RG-B08) : bornes de la dernière répartition envoyée de
 * la région, en brouillon. Écraser (`REPLACE`) : chaque cellule prend la
 * valeur reprise ; Compléter (`FILL`) : seules les cases vides.
 */
class CopyPreviousLimitsRequest extends RegionalWriteRequest
{
    public const REPLACE = 'REPLACE';

    public const FILL = 'FILL';

    protected function permission(): Permission
    {
        return Permission::LimitEdit;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->regionRules(),
            'mode' => ['required', 'string', Rule::in([self::REPLACE, self::FILL])],
        ];
    }

    public function overwrites(): bool
    {
        return $this->validated('mode') === self::REPLACE;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mode.string' => __('limits.copy_mode'),
            'mode.in' => __('limits.copy_mode'),
        ];
    }
}
