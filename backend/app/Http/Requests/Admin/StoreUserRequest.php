<?php

namespace App\Http\Requests\Admin;

use App\Domain\Access\Role;
use App\Domain\Referentials\ClientStatus;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreUserRequest extends FormRequest
{
    // Contrôle des droits avant la validation : un non-Admin reçoit 403, pas 422.
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('username'))) {
            $this->merge(['username' => mb_strtolower(trim($this->input('username')))]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Login nominatif pour le personnel, code client pour les clients (EF-AUTH-01).
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9][a-z0-9._-]*$/', Rule::unique('users', 'username')],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::enum(Role::class)],
            // Obligatoire pour un compte client (EF-ADM-01), interdit pour le personnel.
            'client_id' => [
                'bail',
                Rule::requiredIf(fn () => $this->input('role') === Role::Client->value),
                Rule::prohibitedIf(fn () => $this->input('role') !== Role::Client->value),
                'nullable', 'integer', 'min:1', Rule::exists('clients', 'id'),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Un client inactif est archivé (§3.5) : aucun compte ne s'y rattache.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('client_id') || ! $this->filled('client_id')) {
                    return;
                }

                if (Client::query()->whereKey((int) $this->input('client_id'))->where('status', ClientStatus::Inactive->value)->exists()) {
                    $validator->errors()->add('client_id', __('clients.accounts.client_inactive'));
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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.regex' => __('users.username_format'),
            'client_id.required' => __('clients.accounts.client_required'),
            'client_id.prohibited' => __('users.client_id_client_only'),
        ];
    }
}
