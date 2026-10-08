<?php

namespace App\Http\Problems;

use RuntimeException;

/**
 * Erreur métier rendue au format « Problem Details » (RFC 9457).
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra  Membres supplémentaires (ex. `violations`)
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        ?string $detail = null,
        public readonly array $extra = [],
        public readonly array $headers = [],
        public readonly ?int $statusOverride = null,
    ) {
        parent::__construct($detail ?? $errorCode->title());
    }

    public function status(): int
    {
        return $this->statusOverride ?? $this->errorCode->status();
    }
}
