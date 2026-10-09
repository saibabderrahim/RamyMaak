<?php

namespace App\Http\Requests\Clients;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Création en masse des comptes clients (EF-ADM-02).
 */
class StoreClientAccountsRequest extends FormRequest
{
    public const MAX_CLIENTS = 200;

    // Contrôle des droits avant la validation : sans user.manage, 403 et non 422.
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'client_ids' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_CLIENTS],
            'client_ids.*' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Existence de toute la liste vérifiée en une requête, une fois les
     * identifiants reconnus comme entiers.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $ids = $this->clientIds();
            if (Client::query()->whereKey($ids)->count() !== count($ids)) {
                $validator->errors()->add('client_ids', __('validation.exists', ['attribute' => __('clients.attributes.client_ids')]));
            }
        }];
    }

    /**
     * Identifiants distincts demandés.
     *
     * @return list<int>
     */
    public function clientIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->input('client_ids', []))));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('clients.attributes');
    }
}
