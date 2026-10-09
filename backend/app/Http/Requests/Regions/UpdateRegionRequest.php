<?php

namespace App\Http\Requests\Regions;

use App\Domain\Referentials\ClientStatus;
use App\Models\Client;
use App\Models\Depot;
use App\Models\Region;
use App\Support\BusinessClock;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateRegionRequest extends FormRequest
{
    // Contrôle des droits avant la validation : 403 sans droit, 404 hors périmètre.
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('update', $this->region());
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'depot_id' => ['sometimes', 'nullable', 'integer', Rule::exists('depots', 'id')],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /**
     * Le code ne change pas (clé des fichiers Excel) ; un dépôt désactivé ne
     * peut pas être choisi (il peut être conservé) ; une région ne se
     * désactive pas tant qu'elle a des clients non inactifs, aujourd'hui ou
     * à partir de demain (RG-S04).
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $region = $this->region();

                if ($this->has('code') && (string) $this->input('code') !== $region->code) {
                    $validator->errors()->add('code', __('regions.code_immutable'));
                }

                if ($this->filled('depot_id') && ! $validator->errors()->has('depot_id')
                    && $this->integer('depot_id') !== $region->depot_id
                    && ! Depot::query()->whereKey($this->integer('depot_id'))->value('is_active')) {
                    $validator->errors()->add('depot_id', __('regions.depot_inactive'));
                }

                if ($this->has('is_active') && ! $validator->errors()->has('is_active')
                    && ! $this->boolean('is_active') && $region->is_active
                    && ($count = $this->clientsAttachedTo($region)) > 0) {
                    $validator->errors()->add('is_active', trans_choice('regions.has_clients', $count, ['count' => $count]));
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

    private function region(): Region
    {
        /** @var Region */
        return $this->route('region');
    }

    /**
     * Clients actifs ou bloqués dont la région effective aujourd'hui est
     * celle-ci, ou qui doivent y entrer à une journée suivante.
     */
    private function clientsAttachedTo(Region $region): int
    {
        $today = BusinessClock::today();

        return Client::query()
            ->where('status', '!=', ClientStatus::Inactive->value)
            ->where(fn (Builder $query) => $query
                ->inEffectiveRegions([$region->id], $today)
                ->orWhere(fn (Builder $pending) => $pending
                    ->where('next_region_id', $region->id)
                    ->where('region_change_on', '>', $today->format('Y-m-d'))))
            ->count();
    }
}
