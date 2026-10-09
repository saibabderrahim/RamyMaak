<?php

namespace App\Http\Requests\Clients;

use App\Models\Client;
use App\Models\ClientCategory;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientCategoryRequest extends FormRequest
{
    // Contrôle des droits avant la validation : sans client.manage, 403 et non 422.
    public function authorize(): bool
    {
        return $this->user()->can('manageCategories', Client::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var ClientCategory $category */
        $category = $this->route('category');

        return [
            // Code immuable (clé des fichiers Excel) : renvoyé tel quel, il est accepté.
            'code' => ['sometimes', function (string $attribute, mixed $value, Closure $fail) use ($category): void {
                if (! is_string($value) || mb_strtoupper(trim($value)) !== $category->code) {
                    $fail(__('clients.code_immutable'));
                }
            }],
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('client_categories', 'name')->ignore($category->id)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('clients.category_attributes');
    }
}
