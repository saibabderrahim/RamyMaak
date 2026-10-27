<?php

namespace App\Domain\Auth;

use App\Domain\Push\PushSubscriptions;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Révocation de toutes les sessions d'un utilisateur (EF-AUTH-07) : sessions
 * en base supprimées et jeton « Rester connecté » renouvelé, ce qui invalide
 * les cookies de connexion persistante de tous ses appareils. Les abonnements
 * push du compte disparaissent avec elles (I-103) : déconnexion de tous les
 * appareils, désactivation, réinitialisation du mot de passe.
 */
final class SessionRevoker
{
    public function __construct(private readonly PushSubscriptions $pushSubscriptions) {}

    public function revokeAll(User $user): int
    {
        $deleted = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->delete();

        $user->forceFill(['remember_token' => Str::random(60)])->save();
        $this->pushSubscriptions->forgetUser($user);

        return $deleted;
    }
}
