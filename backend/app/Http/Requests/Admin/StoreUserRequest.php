<?php

namespace App\Http\Requests\Admin;

use App\Domain\Access\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Devient obligatoire pour le rôle client au lot 2 (référentiel clients).
            'client_id' => ['nullable', 'integer', 'min:1', Rule::prohibitedIf(fn () => $this->input('role') !== Role::Client->value)],
            'is_active' => ['sometimes', 'boolean'],
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
        ];
    }
}
