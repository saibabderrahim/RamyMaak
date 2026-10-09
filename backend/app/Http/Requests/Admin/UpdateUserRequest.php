<?php

namespace App\Http\Requests\Admin;

use App\Domain\Access\Role;
use App\Domain\Referentials\ClientStatus;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    // Contrôle des droits avant la validation : un non-Admin reçoit 403, pas 422.
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'role' => ['sometimes', 'required', Rule::enum(Role::class)],
            'client_id' => ['bail', 'sometimes', 'nullable', 'integer', 'min:1', Rule::exists('clients', 'id')],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'unlock' => ['sometimes', 'accepted'],
        ];
    }

    /**
     * Rattachement au client (EF-ADM-01) et garde-fous : un Admin ne peut ni
     * se désactiver ni se retirer le rôle Admin, et la plateforme garde
     * toujours au moins un Admin actif.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkClientLink($validator),
            function (Validator $validator): void {
                /** @var User $target */
                $target = $this->route('user');
                /** @var User $actor */
                $actor = $this->user();

                $role = $this->targetRole();
                $active = $this->has('is_active') ? $this->boolean('is_active') : $target->is_active;

                $wasActiveAdmin = $target->hasAppRole(Role::Admin) && $target->is_active;
                $staysActiveAdmin = $role === Role::Admin && $active;
                if (! $wasActiveAdmin || $staysActiveAdmin) {
                    return;
                }

                $field = $active ? 'role' : 'is_active';

                if ($target->is($actor)) {
                    $validator->errors()->add($field, __('users.cannot_demote_self'));
                } elseif (User::role(Role::Admin->value)->where('is_active', true)->count() <= 1) {
                    $validator->errors()->add($field, __('users.last_admin'));
                }
            },
        ];
    }

    /**
     * Compte client : client obligatoire dès que le rôle ou le rattachement
     * est modifié, et jamais un client inactif (§3.5 : comptes désactivés).
     * Personnel : aucun client.
     */
    private function checkClientLink(Validator $validator): void
    {
        if ($validator->errors()->has('client_id')) {
            return;
        }

        /** @var User $target */
        $target = $this->route('user');

        if ($this->targetRole() !== Role::Client) {
            if ($this->filled('client_id')) {
                $validator->errors()->add('client_id', __('users.client_id_client_only'));
            }

            return;
        }

        $clientId = $this->has('client_id') ? $this->input('client_id') : $target->client_id;
        $clientId = blank($clientId) ? null : (int) $clientId;

        if ($clientId === null) {
            if ($this->has('role') || $this->has('client_id')) {
                $validator->errors()->add('client_id', __('clients.accounts.client_required'));
            }

            return;
        }

        $changesClient = $clientId !== $target->client_id;
        $reactivates = $this->has('is_active') && $this->boolean('is_active') && ! $target->is_active;
        if (! $changesClient && ! $reactivates) {
            // Un compte déjà rattaché à un client devenu inactif reste modifiable.
            return;
        }

        if (Client::query()->whereKey($clientId)->where('status', ClientStatus::Inactive->value)->exists()) {
            $validator->errors()->add(
                $changesClient ? 'client_id' : 'is_active',
                __($changesClient ? 'clients.accounts.client_inactive' : 'clients.accounts.reactivation_blocked'),
            );
        }
    }

    private function targetRole(): ?Role
    {
        /** @var User $target */
        $target = $this->route('user');

        if (! $this->has('role')) {
            return $target->appRole();
        }

        return is_string($this->input('role')) ? Role::tryFrom($this->input('role')) : null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('users.attributes');
    }
}
