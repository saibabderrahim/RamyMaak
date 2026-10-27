<?php

namespace App\Domain\Push;

/**
 * Transport de recette (`PUSH_TRANSPORT=log`) : rien n'est envoyé, chaque
 * message est écrit sur une ligne JSON de storage/logs/push.log
 * ({ at, user_id, endpoint, payload, options }) et compte comme distribué.
 */
final class LogPushTransport implements PushTransport
{
    public function __construct(private readonly ?string $path = null) {}

    public function send(iterable $subscriptions, string $payload, array $options): array
    {
        $decoded = json_decode($payload, true);
        $path = $this->path ?? storage_path('logs/push.log');

        $outcomes = [];
        foreach ($subscriptions as $subscription) {
            $line = json_encode([
                'at' => now()->toIso8601String(),
                'user_id' => $subscription->user_id,
                'endpoint' => $subscription->endpoint,
                'payload' => $decoded ?? $payload,
                'options' => $options,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            file_put_contents($path, $line.PHP_EOL, FILE_APPEND | LOCK_EX);

            $outcomes[] = PushOutcome::fromStatus($subscription->endpoint, 201);
        }

        return $outcomes;
    }
}
