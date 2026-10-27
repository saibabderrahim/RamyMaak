<?php

namespace App\Domain\Push;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use ValueError;

/**
 * Envoi par les services push (Web Push, RFC 8030 et 8291) : charge chiffrée
 * pour chaque abonnement, en-têtes VAPID (clés de la configuration `webpush`),
 * remplissage automatique de la charge (sa taille ne trahit pas le contenu).
 *
 * Un abonnement à la fois : une clé d'appareil inutilisable n'empêche pas
 * l'envoi aux autres. Des clés VAPID invalides, elles, lèvent une exception
 * (tâche en échec) sans compter d'échec aux abonnements.
 */
final class WebPushTransport implements PushTransport
{
    public function __construct(private readonly ?ClientInterface $client = null) {}

    public function send(iterable $subscriptions, string $payload, array $options): array
    {
        $webPush = $this->webPush();
        $headers = array_filter([
            'TTL' => $options['ttl'],
            'urgency' => $options['urgency'],
            'topic' => self::topic($options['topic'] ?? null),
        ], fn ($value) => $value !== null);

        $outcomes = [];
        foreach ($subscriptions as $subscription) {
            $endpoint = $subscription->endpoint;
            // I-104 : jamais d'écriture hors des services push autorisés.
            if (! PushEndpoints::allowed($endpoint)) {
                $outcomes[] = PushOutcome::failed($endpoint, 'Service push non autorisé');

                continue;
            }

            try {
                $webPush->queueNotification(new Subscription(
                    $endpoint,
                    $subscription->public_key,
                    $subscription->auth_token,
                    $subscription->content_encoding ?: ContentEncoding::aes128gcm->value,
                ), $payload, $headers);
                $report = iterator_to_array($webPush->flush(), false)[0] ?? null;
            } catch (\Exception|ValueError $e) {
                // Clé de l'appareil illisible, encodage inconnu : échec de cet abonnement.
                $outcomes[] = PushOutcome::failed($endpoint, $e->getMessage());

                continue;
            }

            $status = $report?->getResponse()?->getStatusCode();
            $outcomes[] = match (true) {
                $report === null => PushOutcome::failed($endpoint, 'Aucune réponse'),
                $status === null => PushOutcome::failed($endpoint, $report->getReason()),
                default => PushOutcome::fromStatus($endpoint, $status, $report->getReason()),
            };
        }

        return $outcomes;
    }

    private function webPush(): WebPush
    {
        $factory = new HttpFactory;
        $webPush = new WebPush(
            ['VAPID' => [
                'subject' => WebPushConfig::subject(),
                'publicKey' => WebPushConfig::publicKey(),
                'privateKey' => (string) config('webpush.vapid.private_key'),
            ]],
            [],
            $this->client ?? new Client([
                'timeout' => 10,
                'connect_timeout' => 5,
                ...(array) config('webpush.client_options', []),
            ]),
            $factory,
            $factory,
            logger: app(LoggerInterface::class),
        );

        $padding = config('webpush.automatic_padding', true);

        return $webPush->setAutomaticPadding(is_numeric($padding) ? (int) $padding : (bool) $padding);
    }

    /**
     * En-tête Topic (RFC 8030 §5.4) : 32 caractères base64url au plus ; le
     * service push remplace un message en attente du même groupe.
     */
    private static function topic(?string $group): ?string
    {
        if ($group === null) {
            return null;
        }

        $topic = substr((string) preg_replace('/[^A-Za-z0-9_-]/', '', $group), 0, 32);

        return $topic === '' ? null : $topic;
    }
}
