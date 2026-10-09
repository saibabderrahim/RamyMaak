<?php

namespace App\Http\Resources;

use App\Domain\Access\Permission;
use App\Models\Client;
use App\Models\Region;
use App\Models\User;
use App\Support\BusinessClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fiche client (EF-CLI-01). `region` est la région effective aujourd'hui,
 * `pending_region_change` le changement programmé (RG-S04) ; `accounts`
 * n'apparaît que pour qui gère les comptes (user.manage).
 *
 * @mixin Client
 */
class ClientResource extends JsonResource
{
    /**
     * Relations à charger pour l'utilisateur donné.
     *
     * @return array<int|string, mixed>
     */
    public static function relations(?User $viewer): array
    {
        $relations = ['category:id,code,name', 'region:id,code,name', 'nextRegion:id,code,name'];

        if ($viewer?->can(Permission::UserManage->value)) {
            $relations['accounts'] = fn ($query) => $query
                ->select(['id', 'client_id', 'username', 'is_active', 'last_login_at'])
                ->orderBy('username');
        }

        return $relations;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $today = BusinessClock::today();
        $pending = $this->hasPendingRegionChange($today);
        $region = $this->next_region_id !== null && $this->effectiveRegionId($today) === $this->next_region_id
            ? $this->nextRegion
            : $this->region;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'trade_name' => $this->trade_name,
            'category' => $this->category === null ? null : [
                'id' => $this->category->id,
                'code' => $this->category->code,
                'name' => $this->category->name,
            ],
            'region' => self::regionRef($region),
            'pending_region_change' => $pending ? [
                'region' => self::regionRef($this->nextRegion),
                'effective_on' => $this->region_change_on?->format('Y-m-d'),
            ] : null,
            'address' => $this->address,
            'wilaya' => $this->wilaya,
            'commune' => $this->commune,
            'lat' => $this->lat === null ? null : (float) $this->lat,
            'lng' => $this->lng === null ? null : (float) $this->lng,
            'phone' => $this->phone,
            'phone2' => $this->phone2,
            'email' => $this->email,
            'nif' => $this->nif,
            'nis' => $this->nis,
            'rc' => $this->rc,
            'ai' => $this->ai,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_reason' => $this->status_reason,
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
            'accounts' => $this->when(
                (bool) $request->user()?->can(Permission::UserManage->value),
                fn () => $this->accounts->sortBy('username')->values()->map(fn (User $account) => [
                    'id' => $account->id,
                    'username' => $account->username,
                    'is_active' => $account->is_active,
                    'last_login_at' => $account->last_login_at?->toIso8601String(),
                ])->all(),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, code: string, name: string}|null
     */
    private static function regionRef(?Region $region): ?array
    {
        return $region === null ? null : ['id' => $region->id, 'code' => $region->code, 'name' => $region->name];
    }
}
