<?php

namespace App\Models;

use App\Domain\Days\CutoffKind;
use App\Domain\Days\RegionDayStatus;
use Database\Factories\RegionDayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Une région pour une journée (§6.1, §10.2).
 *
 * @property int $id
 * @property int $business_day_id
 * @property int $region_id
 * @property RegionDayStatus $status
 * @property int $quota_version
 * @property ?Carbon $quota_published_at
 * @property ?int $quota_published_by
 * @property ?Carbon $quota_last_published_at
 * @property int $distribution_version
 * @property ?Carbon $distribution_published_at
 * @property ?int $distribution_published_by
 * @property ?Carbon $distribution_cutoff_override_at
 * @property ?Carbon $ordering_cutoff_override_at
 */
class RegionDay extends Model
{
    /** @use HasFactory<RegionDayFactory> */
    use HasFactory;

    protected $fillable = ['business_day_id', 'region_id'];

    protected $attributes = [
        'status' => 'QUOTA_DRAFT',
        'quota_version' => 0,
        'distribution_version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'business_day_id' => 'integer',
            'region_id' => 'integer',
            'status' => RegionDayStatus::class,
            'quota_version' => 'integer',
            'quota_published_at' => 'datetime',
            'quota_published_by' => 'integer',
            'quota_last_published_at' => 'datetime',
            'distribution_version' => 'integer',
            'distribution_published_at' => 'datetime',
            'distribution_published_by' => 'integer',
            'distribution_cutoff_override_at' => 'datetime',
            'ordering_cutoff_override_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BusinessDay, $this> */
    public function businessDay(): BelongsTo
    {
        return $this->belongsTo(BusinessDay::class);
    }

    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /** @return HasMany<RegionQuota, $this> */
    public function quotas(): HasMany
    {
        return $this->hasMany(RegionQuota::class);
    }

    /** Quotas publiés au moins une fois (RG-Q03). */
    public function isQuotaPublished(): bool
    {
        return $this->quota_version > 0;
    }

    /**
     * Heure limite applicable à cette région : prolongation accordée par
     * l'ADV (RG-A05), sinon celle de la journée.
     */
    public function effectiveCutoff(CutoffKind $kind): Carbon
    {
        $override = $kind->overrideColumn() === null ? null : $this->getAttribute($kind->overrideColumn());

        return $override ?? $this->businessDay->cutoff($kind);
    }
}
