<?php

namespace App\Http\Resources;

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
            // Les régions couvertes (RG-S01) sont calculées à partir des
            // affectations, introduites au lot 2.
            'perimeter' => [
                'region_ids' => [],
            ],
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
