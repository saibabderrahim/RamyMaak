<?php

namespace App\Domain\Push;

/**
 * Résultat de l'envoi d'un message à un abonnement : `expired` (404 ou 410
 * du service push) supprime l'abonnement, un autre échec le compte (§2.4).
 */
final readonly class PushOutcome
{
    public function __construct(
        public string $endpoint,
        public bool $ok,
        // Statut HTTP du service push ; null sans réponse (réseau, chiffrement).
        public ?int $status = null,
        public bool $expired = false,
        public ?string $reason = null,
    ) {}

    public static function fromStatus(string $endpoint, int $status, ?string $reason = null): self
    {
        return new self($endpoint, $status >= 200 && $status < 300, $status, in_array($status, [404, 410], true), $reason);
    }

    public static function failed(string $endpoint, string $reason): self
    {
        return new self($endpoint, false, reason: $reason);
    }
}
