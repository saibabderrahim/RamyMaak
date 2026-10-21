<?php

namespace App\Http\Requests\Orders;

use App\Support\BusinessClock;
use Carbon\CarbonImmutable;

/**
 * Envoi d'une commande (RG-C01 à RG-C07) : `client_request_id` (UUID
 * généré par l'appareil, RG-C07), `day` (« AAAA-MM-JJ »), `comment`,
 * `lines`. Un renvoi du même identifiant rend la commande existante (200).
 */
class ClientOrderSubmitRequest extends ClientOrderWriteRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'client_request_id' => ['required', 'string', 'uuid'],
            'day' => ['required', ...$this->dayRules()],
            'comment' => $this->commentRules(),
            ...$this->lineRules(),
        ];
    }

    public function requestId(): string
    {
        return (string) $this->validated('client_request_id');
    }

    public function day(): CarbonImmutable
    {
        /** @var CarbonImmutable */
        return BusinessClock::parseDay((string) $this->validated('day'));
    }
}
