<?php

namespace App\Http\Requests\Regions;

use App\Domain\Imports\ImportSheet;
use App\Models\Depot;
use App\Models\Region;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRegionRequest extends FormRequest
{
    /** Code des régions et des dépôts : clé des fichiers Excel (Annexe B.3). */
    public const CODE_PATTERN = '/^[A-Z0-9_-]{2,20}$/';

    // Contrôle des droits avant la validation : un non-Admin reçoit 403, pas 422.
    public function authorize(): bool
    {
        return $this->user()->can('create', Region::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => mb_strtoupper(trim($this->input('code')))]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:'.self::CODE_PATTERN, Rule::unique('regions', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'depot_id' => ['nullable', 'integer', Rule::exists('depots', 'id')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // Les colonnes des imports de quotas sont lues sans distinguer « - » et « _ » :
                // SUD-EST et SUD_EST désigneraient la même colonne (§8.1).
                $code = $this->input('code');
                if (is_string($code) && ! $validator->errors()->has('code')) {
                    $twin = Region::query()->pluck('code')
                        ->first(fn (string $other) => ImportSheet::normalizeHeader($other) === ImportSheet::normalizeHeader($code));
                    if ($twin !== null) {
                        $validator->errors()->add('code', __('regions.code_ambiguous', ['code' => $twin]));
                    }
                }
                if ($this->filled('depot_id') && ! $validator->errors()->has('depot_id')
                    && ! Depot::query()->whereKey($this->integer('depot_id'))->value('is_active')) {
                    $validator->errors()->add('depot_id', __('regions.depot_inactive'));
                }
            },
        ];
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
        return ['code.regex' => __('regions.code_format')];
    }
}
