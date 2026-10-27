<?php

namespace App\Domain\Push;

use App\Models\PushSubscription;
use App\Models\User;

/**
 * Abonnements push des appareils (contrat du lot 8, §2.2 ; I-103) : un
 * abonnement appartient au dernier compte qui l'a enregistré et à sa
 * session, et disparaît avec elle.
 */
final class PushSubscriptions
{
    /**
     * Enregistrement idempotent par `endpoint`, renvoyé par la PWA à chaque
     * ouverture : session, navigateur et `last_used_at` mis à jour. Un
     * appareil partagé passe au compte courant ; l'ancien propriétaire n'y
     * reçoit plus rien. `wasRecentlyCreated` distingue une création.
     *
     * @param  array{endpoint: string, keys: array{p256dh: string, auth: string}, content_encoding?: ?string}  $data
     */
    public function register(User $user, array $data, ?string $sessionId, ?string $userAgent): PushSubscription
    {
        // Création concurrente du même endpoint (deux onglets) : updateOrCreate
        // retombe sur la mise à jour de la ligne gagnante.
        $subscription = PushSubscription::query()->updateOrCreate(['endpoint' => $data['endpoint']], [
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
            'user_agent' => $userAgent !== null && $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null,
            'failures' => 0,
            'last_used_at' => now(),
        ]);

        // Plafond par compte : chaque push parcourt tous les abonnements du compte,
        // les plus anciennement utilisés au-delà du plafond sont retirés.
        $kept = PushSubscription::query()
            ->where('user_id', $user->id)
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->limit(max(1, (int) config('ramymaak.push.max_per_user')))
            ->pluck('id');
        PushSubscription::query()->where('user_id', $user->id)->whereNotIn('id', $kept)->delete();

        return $subscription;
    }

    /** Désabonnement de l'appareil : seulement un abonnement du compte. */
    public function unregister(User $user, string $endpoint): int
    {
        return PushSubscription::query()
            ->where('user_id', $user->id)
            ->where('endpoint', $endpoint)
            ->delete();
    }

    /** Fin d'une session (déconnexion de cet appareil) : ses abonnements. */
    public function forgetSession(User $user, string $sessionId): int
    {
        return $this->forgetSessions($user, [$sessionId]);
    }

    /**
     * Fin des sessions d'un appareil : leurs abonnements.
     *
     * @param  list<string>  $sessionIds
     */
    public function forgetSessions(User $user, array $sessionIds): int
    {
        return $sessionIds === [] ? 0 : PushSubscription::query()
            ->where('user_id', $user->id)
            ->whereIn('session_id', $sessionIds)
            ->delete();
    }

    /**
     * Abonnements des autres appareils (changement de mot de passe) : seuls
     * restent ceux des sessions gardées.
     *
     * @param  list<string>  $keptSessionIds
     */
    public function forgetAllBut(User $user, array $keptSessionIds): int
    {
        return PushSubscription::query()
            ->where('user_id', $user->id)
            ->where(fn ($query) => $query->whereNull('session_id')->orWhereNotIn('session_id', $keptSessionIds))
            ->delete();
    }

    /**
     * Retour d'un appareil par « Rester connecté » : ses abonnements suivent
     * sa nouvelle session, et disparaîtront avec elle.
     *
     * @param  list<string>  $fromSessionIds
     */
    public function moveSessions(User $user, array $fromSessionIds, string $toSessionId): int
    {
        return $fromSessionIds === [] ? 0 : PushSubscription::query()
            ->where('user_id', $user->id)
            ->whereIn('session_id', $fromSessionIds)
            ->update(['session_id' => $toSessionId]);
    }

    /** Fin de toutes les sessions du compte : tous ses abonnements. */
    public function forgetUser(User $user): int
    {
        return PushSubscription::query()->where('user_id', $user->id)->delete();
    }
}
