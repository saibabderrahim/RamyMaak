<?php

namespace App\Jobs;

use App\Domain\Push\PushOutcome;
use App\Domain\Push\PushTransport;
use App\Domain\Push\QuietHours;
use App\Domain\Push\WebPushConfig;
use App\Models\PushSubscription;
use App\Notifications\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Envoi d'un push à chaque appareil abonné d'un utilisateur (contrat du
 * lot 8, §2.4) : 404 ou 410 du service push → abonnement supprimé ; autre
 * échec → un échec de plus (purge à `ramymaak.push.max_failures`) ; succès
 * → compteur remis à zéro et `last_used_at`.
 *
 * Relancée seulement sur une erreur technique (base, clés VAPID) : l'échec
 * d'un appareil est compté, pas rejoué, pour ne pas renvoyer le push aux
 * autres appareils.
 */
class DeliverPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * @param  array<string, mixed>  $payload  charge utile (§2.5)
     */
    public function __construct(public int $userId, public array $payload)
    {
        $this->afterCommit();
    }

    public function handle(PushTransport $transport, QuietHours $quietHours): void
    {
        // Push coupé entre-temps, ou tâche retardée jusque dans la plage sans push (I-105).
        if (! WebPushConfig::available() || $quietHours->active()) {
            return;
        }

        $subscriptions = PushSubscription::query()->where('user_id', $this->userId)->orderBy('id')->get();
        if ($subscriptions->isEmpty()) {
            return;
        }

        $outcomes = $transport->send(
            $subscriptions,
            json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $this->options(),
        );

        $byEndpoint = $subscriptions->keyBy('endpoint');
        foreach ($outcomes as $outcome) {
            $subscription = $byEndpoint->get($outcome->endpoint);
            if ($subscription !== null) {
                $this->record($subscription, $outcome);
            }
        }
    }

    /**
     * En-têtes (§2.4) : durée de vie, urgence haute pour les rappels, sujet =
     * groupe (le service push remplace un message en attente du même groupe).
     *
     * @return array{ttl: int, urgency: string, topic: ?string}
     */
    public function options(): array
    {
        $type = NotificationType::tryFrom((string) ($this->payload['type'] ?? ''));

        return [
            'ttl' => (int) config('ramymaak.push.ttl'),
            'urgency' => $type?->isReminder() ? 'high' : 'normal',
            'topic' => isset($this->payload['tag']) ? (string) $this->payload['tag'] : null,
        ];
    }

    private function record(PushSubscription $subscription, PushOutcome $outcome): void
    {
        if ($outcome->expired) {
            $subscription->delete();
        } elseif (! $outcome->ok) {
            // Colonne sur un octet ; la purge nocturne passe bien avant.
            if ($subscription->failures < 255) {
                $subscription->increment('failures');
            }
        } else {
            $subscription->forceFill(['failures' => 0, 'last_used_at' => now()])->save();
        }
    }
}
