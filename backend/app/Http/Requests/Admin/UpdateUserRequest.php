<?php

namespace App\Http\Requests\Admin;

use App\Domain\Access\Role;
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
            'client_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'unlock' => ['sometimes', 'accepted'],
        ];
    }

    /**
     * Garde-fous : un Admin ne peut ni se désactiver ni se retirer le rôle
     * Admin, et la plateforme garde toujours au moins un Admin actif.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var User $target */
                $target = $this->route('user');
                /** @var User $actor */
                $actor = $this->user();

                $role = $this->has('role') ? Role::tryFrom((string) $this->input('role')) : $target->appRole();
                $active = $this->has('is_active') ? $this->boolean('is_active') : $target->is_active;

                if ($role !== Role::Client && $this->filled('client_id')) {
                    $validator->errors()->add('client_id', __('users.client_id_client_only'));
                }

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
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('users.attributes');
    }
}
