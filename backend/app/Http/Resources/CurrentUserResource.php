<?php

namespace App\Http\Resources;

use App\Domain\Access\Perimeter;
use App\Domain\Auth\PasswordPolicy;
use App\Domain\Push\WebPushConfig;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Profil de l'utilisateur connecté (GET /auth/me) : rôle, permissions,
 * périmètre.
 *
 * @mixin User
 */
class CurrentUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $role = $this->appRole();
        // Régions couvertes aujourd'hui (§3.3) : recalculées à chaque appel,
        // une affectation ajoutée ou révoquée compte aussitôt (RG-S03).
        $regionIds = app(Perimeter::class)->regionIds($this->resource);

        return [
            'id' => $this->id,
            'username' => $this->username,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $role?->value,
            'role_label' => $role?->label(),
            'permissions' => $this->getAllPermissions()->pluck('name')->sort()->values(),
            'client_id' => $this->client_id,
            'must_change_password' => $this->must_change_password,
            'password_min_length' => app(PasswordPolicy::class)->minLength($role),
            'perimeter' => [
                'all_regions' => $regionIds === null,
                'region_ids' => $regionIds ?? [],
            ],
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            // Temps réel (§9.6) : clé publique de l'application Reverb, connexion
            // au même domaine (chemin /app) ; null : rafraîchissement périodique.
            'realtime' => config('broadcasting.default') === 'reverb' && filled(config('broadcasting.connections.reverb.key'))
                ? ['key' => (string) config('broadcasting.connections.reverb.key')]
                : null,
            // Notifications push (lot 8, §2.1) : clé publique VAPID pour abonner l'appareil ; null : pas de push.
            'push' => WebPushConfig::available()
                ? ['public_key' => WebPushConfig::publicKey()]
                : null,
            // Préférences du compte (lot 8, §4).
            'preferences' => [
                'reminders' => (bool) ($this->reminders_enabled ?? true),
            ],
        ];
    }
}
