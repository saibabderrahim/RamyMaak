<?php

namespace App\Http\Resources;

use App\Models\RegionAssignment;
use App\Models\User;
use App\Support\BusinessClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Affectation à une région (§3.3, §3.4). `is_active` : active aujourd'hui
 * (jour métier, H-10).
 *
 * @mixin RegionAssignment
 */
class AssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'region_id' => $this->region_id,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'user' => self::userRef($this->user),
            'starts_on' => $this->starts_on->format('Y-m-d'),
            'ends_on' => $this->ends_on?->format('Y-m-d'),
            'is_active' => $this->isActiveOn(BusinessClock::today()),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revoked_by' => $this->revoked_by === null ? null : self::userRef($this->revokedBy),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, username: string, name: string}|null
     */
    private static function userRef(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'username' => $user->username, 'name' => $user->name];
    }
}
