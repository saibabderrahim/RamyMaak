<?php

namespace App\Domain\Push;

use App\Models\PushSubscription;

/**
 * Envoi d'un message push aux abonnements d'un utilisateur (contrat du
 * lot 8, §2.4) : services push réels (WebPushTransport), journal de recette
 * (LogPushTransport) ou faux transport des tests (FakePushTransport).
 */
interface PushTransport
{
    /**
     * Un résultat par abonnement, dans l'ordre reçu ; un échec propre à un
     * abonnement est un résultat, pas une exception.
     *
     * @param  iterable<PushSubscription>  $subscriptions
     * @param  string  $payload  charge utile JSON (§2.5)
     * @param  array{ttl: int, urgency: string, topic: ?string}  $options  en-têtes TTL, Urgency et Topic
     * @return list<PushOutcome>
     */
    public function send(iterable $subscriptions, string $payload, array $options): array;
}
