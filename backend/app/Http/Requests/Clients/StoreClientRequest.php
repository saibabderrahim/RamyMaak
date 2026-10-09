<?php

namespace App\Http\Requests\Clients;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreClientRequest extends FormRequest
{
    use ClientFicheRules;

    // Contrôle des droits avant la validation : sans client.manage, 403 et non 422.
    public function authorize(): bool
    {
        return $this->user()->can('create', Client::class);
    }

    protected function prepareForValidation(): void
    {
        // Code ERP, clé des fichiers Excel : conservé en majuscules.
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
            'code' => ['required', 'string', 'regex:/^[A-Z0-9_-]{1,30}$/', Rule::unique('clients', 'code')],
            ...$this->ficheRules(null),
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkCoordinates($validator, null)];
    }
}
