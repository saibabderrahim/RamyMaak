<?php

namespace App\Http\Requests\Auth;

use App\Domain\Auth\PasswordPolicy;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

class ChangePasswordRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(PasswordPolicy $policy): array
    {
        /** @var User $user */
        $user = $this->user();

        return [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => [...$policy->rules($user->appRole(), $user->username), 'confirmed'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var User $user */
                $user = $this->user();

                if (! $validator->errors()->has('password')
                    && Hash::check((string) $this->input('password'), $user->password)) {
                    $validator->errors()->add('password', __('passwords.same_as_current'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'current_password' => 'mot de passe actuel',
            'password' => 'nouveau mot de passe',
        ];
    }
}
