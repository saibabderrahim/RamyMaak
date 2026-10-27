<?php

namespace App\Http\Requests\Push;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Désabonnement de l'appareil (contrat du lot 8, §2.2). Tous les rôles.
 */
class DestroyPushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('push.attributes');
    }
}
