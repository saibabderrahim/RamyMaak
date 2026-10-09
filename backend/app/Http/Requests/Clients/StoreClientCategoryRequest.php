<?php

namespace App\Http\Requests\Clients;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientCategoryRequest extends FormRequest
{
    // Contrôle des droits avant la validation : sans client.manage, 403 et non 422.
    public function authorize(): bool
    {
        return $this->user()->can('manageCategories', Client::class);
    }

    protected function prepareForValidation(): void
    {
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
            'code' => ['required', 'string', 'regex:/^[A-Z0-9_-]{1,20}$/', Rule::unique('client_categories', 'code')],
            // Libellé unique : l'import Excel reconnaît une catégorie par son code ou son libellé.
            'name' => ['required', 'string', 'max:255', Rule::unique('client_categories', 'name')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('clients.category_attributes');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['code.regex' => __('clients.category_code_format')];
    }
}
