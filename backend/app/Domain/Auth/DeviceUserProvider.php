<?php

namespace App\Domain\Auth;

use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Comptes de l'application (pilote `eloquent_devices`, config/auth.php) :
 * celui d'Eloquent, avec un jeton « Rester connecté » par appareil
 * (RememberTokens, I-113) au lieu de la colonne `users.remember_token`
 * partagée par tous les appareils du compte.
 */
final class DeviceUserProvider extends EloquentUserProvider
{
    /**
     * Compte du cookie « Rester connecté », si le jeton est celui d'un de
     * ses appareils.
     *
     * @param  mixed  $identifier
     * @param  string  $token
     */
    public function retrieveByToken($identifier, #[\SensitiveParameter] $token): ?User
    {
        if (! is_string($token) || $token === '') {
            return null;
        }
        $user = $this->retrieveById($identifier);

        return $user instanceof User && app(RememberTokens::class)->recall($user, $token) ? $user : null;
    }

    /**
     * Jeton de l'appareil créé à la connexion, ou renouvelé.
     *
     * @param  string  $token
     */
    public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] $token): void
    {
        if ($user instanceof User) {
            app(RememberTokens::class)->store($user, (string) $token);
        }
    }
}
