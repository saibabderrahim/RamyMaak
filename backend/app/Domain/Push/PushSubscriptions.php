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
        return PushSubscription::query()->updateOrCreate(['endpoint' => $data['endpoint']], [
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
            'user_agent' => $userAgent !== null && $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null,
            'failures' => 0,
            'last_used_at' => now(),
        ]);
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
        return PushSubscription::query()
            ->where('user_id', $user->id)
            ->where('session_id', $sessionId)
            ->delete();
    }

    /** Fin de toutes les sessions du compte : tous ses abonnements. */
    public function forgetUser(User $user): int
    {
        return PushSubscription::query()->where('user_id', $user->id)->delete();
    }
}
