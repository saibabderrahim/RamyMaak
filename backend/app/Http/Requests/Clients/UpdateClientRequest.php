<?php

namespace App\Http\Requests\Clients;

use App\Domain\Referentials\ClientStatus;
use App\Models\Client;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateClientRequest extends FormRequest
{
    use ClientFicheRules;

    protected function prepareForValidation(): void
    {
        // Un motif seul modifie le motif du statut actuel.
        if ($this->has('status_reason') && ! $this->has('status')) {
            $this->merge(['status' => $this->targetClient()->status->value]);
        }
    }

    /**
     * Contrôle des droits avant la validation : client.manage et périmètre
     * (404 hors périmètre), puis client.block si le statut ou son motif
     * change (EF-CLI-04).
     */
    public function authorize(): Response
    {
        $gate = Gate::forUser($this->user());
        $response = $gate->inspect('update', $this->targetClient());

        if ($response->denied() || ! $this->changesStatus()) {
            return $response;
        }

        return $gate->inspect('changeStatus', $this->targetClient());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Le code ne change pas (clé ERP) : renvoyé tel quel, il est accepté.
            'code' => ['sometimes', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || mb_strtoupper(trim($value)) !== $this->targetClient()->code) {
                    $fail(__('clients.code_immutable'));
                }
            }],
            ...$this->ficheRules($this->targetClient()),
            'status' => ['sometimes', 'required', Rule::enum(ClientStatus::class)],
            'status_reason' => ['nullable', 'string', 'max:255', 'required_if:status,'.ClientStatus::Blocked->value],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkCoordinates($validator, $this->targetClient())];
    }

    public function targetClient(): Client
    {
        /** @var Client */
        return $this->route('client');
    }

    private function changesStatus(): bool
    {
        $client = $this->targetClient();

        if ($this->has('status') && $this->input('status') !== $client->status->value) {
            return true;
        }

        if (! $this->has('status_reason')) {
            return false;
        }

        $reason = $this->input('status_reason');
        $reason = is_string($reason) && trim($reason) !== '' ? trim($reason) : null;

        return $reason !== $client->status_reason;
    }
}
