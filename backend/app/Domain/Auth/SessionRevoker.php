<?php

namespace App\Domain\Auth;

use App\Domain\Push\PushSubscriptions;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Révocation des sessions d'un utilisateur (EF-AUTH-07) : sessions
 * en base et jetons « Rester connecté » de tous ses appareils supprimés
 * (I-113), ce qui invalide leurs cookies de connexion persistante ; l'ancienne
 * colonne `remember_token` est renouvelée par compatibilité. Les abonnements
 * push du compte disparaissent avec elles (I-103) : déconnexion de tous les
 * appareils, désactivation, réinitialisation du mot de passe.
 */
final class SessionRevoker
{
    public function __construct(
        private readonly PushSubscriptions $pushSubscriptions,
        private readonly RememberTokens $rememberTokens,
    ) {}

    public function revokeAll(User $user): int
    {
        $deleted = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->delete();

        $user->forceFill(['remember_token' => Str::random(60)])->save();
        $this->rememberTokens->forgetUser($user);
        $this->pushSubscriptions->forgetUser($user);

        return $deleted;
    }

    /**
     * Déconnexion d'un appareil (liste des appareils, « Me déconnecter ») :
     * toutes ses sessions, ses jetons « Rester connecté » et ses abonnements
     * push (I-103, I-113).
     *
     * @param  list<string>  $sessionIds  sessions de l'appareil (RememberTokens::deviceSessions)
     * @param  list<int>  $tokenIds
     * @return array{sessions: int, remember_tokens: int, push_subscriptions: int}
     */
    public function revokeDevice(User $user, array $sessionIds, array $tokenIds): array
    {
        $tokenIds = array_values(array_unique([
            ...$tokenIds,
            ...array_merge([], ...array_map(fn (string $id) => $this->rememberTokens->tokensOfSession($user, $id), $sessionIds)),
        ]));

        return [
            'sessions' => $sessionIds === [] ? 0 : DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->whereIn('id', $sessionIds)
                ->delete(),
            'push_subscriptions' => $this->pushSubscriptions->forgetSessions($user, $sessionIds),
            'remember_tokens' => $this->rememberTokens->forgetTokens($user, $tokenIds),
        ];
    }

    /**
     * Changement du mot de passe : les autres appareils sont déconnectés
     * (leurs sessions, jetons et abonnements), l'appareil courant reste
     * connecté, avec son jeton s'il en a un.
     *
     * @return array{sessions: int, remember_tokens: int, push_subscriptions: int}
     */
    public function revokeOtherDevices(User $user, string $currentSessionId, ?int $keptTokenId): array
    {
        $kept = $this->rememberTokens->deviceSessions($user, $currentSessionId);
        if ($keptTokenId !== null) {
            $kept = array_values(array_unique([...$kept, ...$this->rememberTokens->sessionsOfTokens([$keptTokenId])]));
        }

        return [
            'sessions' => DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->whereNotIn('id', $kept)
                ->delete(),
            'push_subscriptions' => $this->pushSubscriptions->forgetAllBut($user, $kept),
            'remember_tokens' => $this->rememberTokens->forgetAllBut($user, $keptTokenId),
        ];
    }
}
