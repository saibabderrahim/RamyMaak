<?php

namespace App\Models;

use App\Casts\BusinessDate;
use App\Domain\Referentials\ClientStatus;
use App\Models\Concerns\LogsReferentialChanges;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;

/**
 * Client de Ramy (EF-CLI-01). Un client appartient à une seule région (H-02) ;
 * un changement de région prend effet à la journée suivante (RG-S04) : il
 * reste en attente dans next_region_id / region_change_on jusque-là.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property ?string $trade_name
 * @property ?int $category_id
 * @property int $region_id
 * @property ?int $next_region_id
 * @property ?CarbonImmutable $region_change_on
 * @property ?string $address
 * @property ?string $wilaya
 * @property ?string $commune
 * @property ?string $lat
 * @property ?string $lng
 * @property ?string $phone
 * @property ?string $phone2
 * @property ?string $email
 * @property ?string $nif
 * @property ?string $nis
 * @property ?string $rc
 * @property ?string $ai
 * @property ClientStatus $status
 * @property ?string $status_reason
 * @property ?Carbon $status_changed_at
 * @property ?int $status_changed_by
 */
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    use LogsReferentialChanges {
        getActivitylogOptions as referentialLogOptions;
    }

    /** Champs modifiables directement ; région et statut passent par ClientRegistry. */
    protected $fillable = [
        'code', 'name', 'trade_name', 'category_id', 'address', 'wilaya', 'commune',
        'lat', 'lng', 'phone', 'phone2', 'email', 'nif', 'nis', 'rc', 'ai',
    ];

    protected $attributes = [
        'status' => 'ACTIVE',
    ];

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'region_id' => 'integer',
            'next_region_id' => 'integer',
            'region_change_on' => BusinessDate::class,
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'status' => ClientStatus::class,
            'status_changed_at' => 'datetime',
            'status_changed_by' => 'integer',
        ];
    }

    /** @return BelongsTo<ClientCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ClientCategory::class, 'category_id');
    }

    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /** @return BelongsTo<Region, $this> */
    public function nextRegion(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'next_region_id');
    }

    /** @return BelongsTo<User, $this> */
    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    /**
     * Comptes utilisateurs du client (Q-11 : plusieurs comptes possibles).
     *
     * @return HasMany<User, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(User::class, 'client_id');
    }

    /** Région effective le jour donné (RG-S04). */
    public function effectiveRegionId(CarbonInterface $day): int
    {
        if ($this->next_region_id !== null && $this->region_change_on !== null
            && $this->region_change_on->format('Y-m-d') <= $day->format('Y-m-d')) {
            return $this->next_region_id;
        }

        return $this->region_id;
    }

    /** Changement de région programmé après le jour donné. */
    public function hasPendingRegionChange(CarbonInterface $day): bool
    {
        return $this->next_region_id !== null && $this->region_change_on !== null
            && $this->region_change_on->format('Y-m-d') > $day->format('Y-m-d');
    }

    /**
     * Clients dont la région effective le jour donné est l'une des régions.
     *
     * @param  Builder<Client>  $query
     * @param  list<int>  $regionIds
     */
    public function scopeInEffectiveRegions(Builder $query, array $regionIds, CarbonInterface $day): void
    {
        if ($regionIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $date = $day->format('Y-m-d');

        $query->where(function (Builder $q) use ($regionIds, $date) {
            $q->where(function (Builder $current) use ($regionIds, $date) {
                $current->whereIn('region_id', $regionIds)
                    ->where(fn (Builder $p) => $p->whereNull('region_change_on')->orWhere('region_change_on', '>', $date));
            })->orWhere(function (Builder $next) use ($regionIds, $date) {
                $next->whereIn('next_region_id', $regionIds)->where('region_change_on', '<=', $date);
            });
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return $this->referentialLogOptions()->logOnly([
            'region_id', 'next_region_id', 'region_change_on', 'status', 'status_reason',
        ]);
    }

    protected static function auditDescriptions(): array
    {
        return ['created' => 'Client créé', 'updated' => 'Client modifié', 'deleted' => 'Client supprimé'];
    }
}
