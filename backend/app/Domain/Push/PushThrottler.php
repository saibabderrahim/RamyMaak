<?php

namespace App\Domain\Push;

use App\Jobs\DeliverPush;
use App\Jobs\FlushPush;
use App\Models\PushSubscription;
use App\Models\PushThrottle;
use App\Models\User;
use App\Notifications\NotificationType;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/**
 * Anti-saturation des push (EF-NOT-02, I-109 ; contrat du lot 8, §2.4) : par
 * utilisateur et par groupe, un push immédiat, puis au plus un push groupé
 * par fenêtre (`ramymaak.push.group_window_minutes`). L'état `push_throttles`
 * est verrouillé pendant chaque décision : deux tâches concurrentes ne
 * peuvent pas envoyer deux push immédiats ni programmer deux envois groupés.
 */
final class PushThrottler
{
    /** Secondes d'avance tolérées pour l'envoi groupé (horloges des serveurs). */
    private const EARLY_TOLERANCE = 5;

    public function __construct(private readonly QuietHours $quietHours) {}

    /**
     * Push possible maintenant pour cet utilisateur : push disponible (clés
     * VAPID, paramètre général), hors de la plage sans push, au moins un
     * abonnement.
     */
    public function deliverable(int $userId): bool
    {
        return WebPushConfig::available()
            && ! $this->quietHours->active()
            && PushSubscription::query()->where('user_id', $userId)->exists();
    }

    /**
     * Push d'une notification (canal push) : envoyé tout de suite si aucun
     * push du groupe n'est parti pendant la fenêtre, sinon compté pour le
     * push groupé de la fin de la fenêtre.
     *
     * @param  array<string, mixed>  $payload  charge utile du push seul (PushPayload::single)
     */
    public function submit(User $user, NotificationType $type, array $payload): void
    {
        $group = $type->pushGroup();
        $window = $this->windowMinutes();
        $this->ensureState($user->id, $group);

        [$deliver, $flushAt] = DB::transaction(function () use ($user, $group, $window, $payload): array {
            $throttle = $this->lock($user->id, $group);
            $now = CarbonImmutable::now();
            $lastSentAt = $throttle->last_sent_at?->toImmutable();

            if ($throttle->pending === 0 && ($lastSentAt === null || $lastSentAt->lte($now->subMinutes($window)))) {
                $throttle->forceFill(['last_sent_at' => $now, 'flush_at' => null, 'last_title' => null])->save();

                return [true, null];
            }

            $throttle->pending++;
            $throttle->last_title = mb_substr((string) ($payload['title'] ?? ''), 0, 255);

            // Un seul envoi groupé programmé par utilisateur et par groupe. Un
            // envoi resté en plan au-delà d'une fenêtre (tâche perdue) est
            // reprogrammé : l'état ne reste jamais bloqué.
            $flushAt = null;
            $scheduled = $throttle->flush_at?->toImmutable();
            if ($scheduled === null || $scheduled->lt($now->subMinutes($window))) {
                $flushAt = ($lastSentAt ?? $now)->addMinutes($window)->max($now);
                $throttle->flush_at = $flushAt;
            }
            $throttle->save();

            return [false, $flushAt];
        }, 3);

        if ($deliver) {
            DeliverPush::dispatch($user->id, $payload);
        }
        if ($flushAt !== null) {
            FlushPush::dispatch($user->id, $group)->delay($flushAt);
        }
    }

    /**
     * Fin de la fenêtre (tâche FlushPush) : push groupé s'il reste des
     * notifications comptées et que le push est possible, compteur remis à
     * zéro dans tous les cas. Rejouable : une tâche relancée ou en double
     * trouve le compteur à zéro et n'envoie rien.
     */
    public function flush(int $userId, string $group): void
    {
        $payload = DB::transaction(function () use ($userId, $group): ?array {
            $throttle = PushThrottle::query()
                ->where('user_id', $userId)
                ->where('group', $group)
                ->lockForUpdate()
                ->first();
            if ($throttle === null || $throttle->flush_at === null) {
                return null;
            }
            // Tâche en avance (doublon d'un envoi reprogrammé) : celle de l'heure prévue s'en charge.
            if ($throttle->flush_at->isAfter(now()->addSeconds(self::EARLY_TOLERANCE))) {
                return null;
            }

            $payload = null;
            if ($throttle->pending > 0 && $this->deliverable($userId)) {
                $payload = $throttle->pending === 1
                    ? $this->latestPayload($userId, $group, $throttle->last_title)
                    : PushPayload::grouped($group, $throttle->pending, $throttle->last_title);
                $throttle->last_sent_at = now();
            }

            $throttle->forceFill(['pending' => 0, 'flush_at' => null, 'last_title' => null])->save();

            return $payload;
        }, 3);

        if ($payload !== null) {
            DeliverPush::dispatch($userId, $payload);
        }
    }

    public function windowMinutes(): int
    {
        return max(1, (int) config('ramymaak.push.group_window_minutes'));
    }

    /**
     * Une seule notification comptée : elle part seule, avec son texte et son
     * écran, plutôt qu'en « 1 nouvelle notification ».
     *
     * @return array<string, mixed>
     */
    private function latestPayload(int $userId, string $group, ?string $lastTitle): array
    {
        $notification = DatabaseNotification::query()
            ->where('notifiable_type', (new User)->getMorphClass())
            ->where('notifiable_id', $userId)
            ->where('type', $group)
            ->latest()
            ->first();

        return $notification !== null
            ? PushPayload::stored($notification, $group)
            : PushPayload::grouped($group, 1, $lastTitle);
    }

    /**
     * Crée l'état du groupe s'il n'existe pas encore, hors transaction : deux
     * premières notifications simultanées n'en créent qu'un (clé unique), et
     * le verrou qui suit porte toujours sur une ligne existante.
     */
    private function ensureState(int $userId, string $group): void
    {
        if (PushThrottle::query()->where('user_id', $userId)->where('group', $group)->exists()) {
            return;
        }

        $now = now();
        PushThrottle::query()->insertOrIgnore([
            'user_id' => $userId,
            'group' => $group,
            'pending' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function lock(int $userId, string $group): PushThrottle
    {
        return PushThrottle::query()
            ->where('user_id', $userId)
            ->where('group', $group)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
