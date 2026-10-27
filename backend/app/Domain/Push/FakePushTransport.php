<?php

namespace App\Domain\Push;

/**
 * Faux transport des tests : enregistre les messages au lieu de les envoyer,
 * avec une réponse simulée par abonnement (201 par défaut ; 0 : pas de
 * réponse, comme une erreur de réseau).
 */
final class FakePushTransport implements PushTransport
{
    /** @var list<array{user_id: ?int, endpoints: list<string>, payload: array<string, mixed>, options: array<string, mixed>}> */
    public array $sent = [];

    /** @var array<string, int> */
    private array $statuses = [];

    /** Remplace le transport de l'application par un faux transport. */
    public static function install(): self
    {
        $fake = new self;
        app()->instance(PushTransport::class, $fake);

        return $fake;
    }

    /** Réponse simulée du service push pour cet abonnement. */
    public function respondWith(string $endpoint, int $status): self
    {
        $this->statuses[$endpoint] = $status;

        return $this;
    }

    public function send(iterable $subscriptions, string $payload, array $options): array
    {
        $outcomes = [];
        $endpoints = [];
        $userId = null;
        foreach ($subscriptions as $subscription) {
            $userId ??= $subscription->user_id;
            $endpoints[] = $subscription->endpoint;
            $status = $this->statuses[$subscription->endpoint] ?? 201;
            $outcomes[] = $status === 0
                ? PushOutcome::failed($subscription->endpoint, 'Erreur de réseau simulée')
                : PushOutcome::fromStatus($subscription->endpoint, $status);
        }

        $this->sent[] = [
            'user_id' => $userId,
            'endpoints' => $endpoints,
            'payload' => (array) json_decode($payload, true, flags: JSON_THROW_ON_ERROR),
            'options' => $options,
        ];

        return $outcomes;
    }

    /**
     * Charges utiles envoyées, à un utilisateur ou à tous.
     *
     * @return list<array<string, mixed>>
     */
    public function payloads(?int $userId = null): array
    {
        return array_values(array_map(
            fn (array $message) => $message['payload'],
            array_filter($this->sent, fn (array $message) => $userId === null || $message['user_id'] === $userId),
        ));
    }
}
