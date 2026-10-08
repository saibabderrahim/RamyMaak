<?php

namespace App\Domain\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Révocation de toutes les sessions d'un utilisateur (EF-AUTH-07) : sessions
 * en base supprimées et jeton « Rester connecté » renouvelé, ce qui invalide
 * les cookies de connexion persistante de tous ses appareils.
 */
final class SessionRevoker
{
    public function revokeAll(User $user): int
    {
        $deleted = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->delete();

        $user->forceFill(['remember_token' => Str::random(60)])->save();

        return $deleted;
    }
}
