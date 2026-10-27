<?php

namespace App\Domain\Auth;

use App\Domain\Push\PushSubscriptions;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Révocation de toutes les sessions d'un utilisateur (EF-AUTH-07) : sessions
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
}
