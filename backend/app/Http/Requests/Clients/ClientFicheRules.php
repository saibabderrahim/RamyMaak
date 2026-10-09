<?php

namespace App\Http\Requests\Clients;

use App\Domain\Access\Perimeter;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;

/**
 * Règles communes de la fiche client (EF-CLI-01), en création ($client null)
 * comme en modification partielle.
 *
 * @mixin FormRequest
 */
trait ClientFicheRules
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function ficheRules(?Client $client): array
    {
        $optional = $client === null ? [] : ['sometimes'];
        $mandatory = [...$optional, 'required'];

        return [
            'name' => [...$mandatory, 'string', 'max:255'],
            'trade_name' => [...$optional, 'nullable', 'string', 'max:255'],
            'category_id' => [...$optional, 'nullable', 'integer', Rule::exists('client_categories', 'id')],
            'region_id' => [...$mandatory, 'integer', $this->availableRegion($client)],
            'address' => [...$optional, 'nullable', 'string', 'max:255'],
            'wilaya' => [...$optional, 'nullable', 'string', 'max:100'],
            'commune' => [...$optional, 'nullable', 'string', 'max:100'],
            'lat' => [...$optional, 'nullable', 'numeric', 'between:-90,90'],
            'lng' => [...$optional, 'nullable', 'numeric', 'between:-180,180'],
            'phone' => [...$optional, 'nullable', 'string', 'max:30'],
            'phone2' => [...$optional, 'nullable', 'string', 'max:30'],
            'email' => [...$optional, 'nullable', 'email:rfc', 'max:255'],
            'nif' => [...$optional, 'nullable', 'string', 'max:30'],
            'nis' => [...$optional, 'nullable', 'string', 'max:30'],
            'rc' => [...$optional, 'nullable', 'string', 'max:30'],
            'ai' => [...$optional, 'nullable', 'string', 'max:30'],
        ];
    }

    /**
     * Région active et dans le périmètre de l'auteur ; la région actuelle (ou
     * programmée) du client reste acceptée même désactivée depuis, pour qu'un
     * formulaire renvoyé tel quel ne soit pas refusé.
     */
    private function availableRegion(?Client $client): Exists
    {
        /** @var User $actor */
        $actor = $this->user();
        $allowed = app(Perimeter::class)->regionIds($actor);
        $kept = array_values(array_filter([$client?->region_id, $client?->next_region_id]));

        return Rule::exists('regions', 'id')->where(function (Builder $query) use ($allowed, $kept): void {
            $query->where(fn (Builder $q) => $q->where('is_active', true)->when($kept !== [], fn ($q) => $q->orWhereIn('id', $kept)));
            if ($allowed !== null) {
                $query->whereIn('id', $allowed);
            }
        });
    }

    /** Coordonnées GPS : les deux ou aucune, en tenant compte des valeurs actuelles. */
    protected function checkCoordinates(Validator $validator, ?Client $client): void
    {
        if ($validator->errors()->hasAny(['lat', 'lng'])) {
            return;
        }

        $lat = $this->has('lat') ? $this->input('lat') : $client?->lat;
        $lng = $this->has('lng') ? $this->input('lng') : $client?->lng;

        if (($lat === null) !== ($lng === null)) {
            $validator->errors()->add($lat === null ? 'lat' : 'lng', __('clients.coordinates_pair'));
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('clients.attributes');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => __('clients.code_format'),
            'region_id.exists' => __('clients.region_unavailable'),
            'status_reason.required_if' => __('clients.status_reason_required'),
        ];
    }
}
