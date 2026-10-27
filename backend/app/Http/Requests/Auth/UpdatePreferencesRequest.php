<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Préférences du compte (contrat du lot 8, §4) : `reminders`, rappels
 * N-10 à N-12 reçus ou non (EF-NOT-04).
 */
class UpdatePreferencesRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reminders' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reminders' => 'rappels',
        ];
    }
}
