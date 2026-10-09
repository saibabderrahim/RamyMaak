<?php

namespace App\Models;

use App\Casts\BusinessDate;
use App\Domain\Referentials\AssignmentKind;
use App\Models\Concerns\LogsReferentialChanges;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\RegionAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\LogOptions;

/**
 * Affectation d'un utilisateur à une région (§3.3, §3.4). Active le jour D
 * si elle n'est pas révoquée et si starts_on ≤ D ≤ ends_on (bornes
 * incluses, ends_on facultative).
 *
 * @property int $id
 * @property int $region_id
 * @property int $user_id
 * @property AssignmentKind $kind
 * @property CarbonImmutable $starts_on
 * @property ?CarbonImmutable $ends_on
 * @property ?Carbon $revoked_at
 * @property ?int $revoked_by
 * @property ?int $created_by
 */
class RegionAssignment extends Model
{
    /** @use HasFactory<RegionAssignmentFactory> */
    use HasFactory;

    use LogsReferentialChanges {
        getActivitylogOptions as referentialLogOptions;
    }

    protected $fillable = ['region_id', 'user_id', 'kind', 'starts_on', 'ends_on', 'created_by'];

    protected function casts(): array
    {
        return [
            'region_id' => 'integer',
            'user_id' => 'integer',
            'kind' => AssignmentKind::class,
            'starts_on' => BusinessDate::class,
            'ends_on' => BusinessDate::class,
            'revoked_at' => 'datetime',
            'revoked_by' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<RegionAssignment>  $query
     */
    public function scopeActiveOn(Builder $query, CarbonInterface $day): void
    {
        $date = $day->format('Y-m-d');

        $query->whereNull('revoked_at')
            ->where('starts_on', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date));
    }

    /**
     * Affectations encore utiles : en cours ou à venir, non révoquées.
     *
     * @param  Builder<RegionAssignment>  $query
     */
    public function scopeCurrentOrUpcoming(Builder $query, CarbonInterface $day): void
    {
        $date = $day->format('Y-m-d');

        $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date));
    }

    public function isActiveOn(CarbonInterface $day): bool
    {
        $date = $day->format('Y-m-d');

        return $this->revoked_at === null
            && $this->starts_on->format('Y-m-d') <= $date
            && ($this->ends_on === null || $this->ends_on->format('Y-m-d') >= $date);
    }

    protected static function auditDescriptions(): array
    {
        return ['created' => 'Affectation créée', 'updated' => 'Affectation modifiée', 'deleted' => 'Affectation supprimée'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        // revoked_at / revoked_by ne sont pas « fillable » mais doivent être tracés.
        return $this->referentialLogOptions()->logOnly(['revoked_at', 'revoked_by']);
    }

    /**
     * Une révocation (RG-S03) est un événement à part entière du journal
     * (EF-ADM-06), filtrable par action.
     */
    public function tapActivity(Activity $activity, string $eventName): void
    {
        if ($eventName === 'updated' && $this->revoked_at !== null && $this->wasChanged('revoked_at')) {
            $activity->event = 'revoked';
            $activity->description = 'Affectation révoquée';
        }
    }
}
