<?php

namespace App\Models;

use App\Casts\BusinessDate;
use App\Domain\Days\BusinessDayStatus;
use App\Domain\Days\CutoffKind;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Database\Factories\BusinessDayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Journée commerciale (§10.2) : heures limites en UTC, copiées des paramètres
 * à l'ouverture puis modifiables par l'ADV pour cette journée (§4.3).
 *
 * @property int $id
 * @property CarbonImmutable $day
 * @property ?CarbonImmutable $delivery_date
 * @property Carbon $distribution_cutoff_at
 * @property Carbon $ordering_cutoff_at
 * @property Carbon $validation_cutoff_at
 * @property Carbon $closing_at
 * @property BusinessDayStatus $status
 * @property ?Carbon $closed_at
 * @property ?int $created_by
 */
class BusinessDay extends Model
{
    /** @use HasFactory<BusinessDayFactory> */
    use HasFactory;

    protected $fillable = [
        'day', 'delivery_date', 'distribution_cutoff_at', 'ordering_cutoff_at',
        'validation_cutoff_at', 'closing_at', 'created_by',
    ];

    protected $attributes = [
        'status' => 'OPEN',
    ];

    protected function casts(): array
    {
        return [
            'day' => BusinessDate::class,
            'delivery_date' => BusinessDate::class,
            'distribution_cutoff_at' => 'datetime',
            'ordering_cutoff_at' => 'datetime',
            'validation_cutoff_at' => 'datetime',
            'closing_at' => 'datetime',
            'status' => BusinessDayStatus::class,
            'closed_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** Journée « AAAA-MM-JJ » existante, ou null. */
    public static function forDay(string $day): ?self
    {
        return BusinessClock::parseDay($day) === null ? null : self::query()->where('day', $day)->first();
    }

    /** @return HasMany<RegionDay, $this> */
    public function regionDays(): HasMany
    {
        return $this->hasMany(RegionDay::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return $this->status === BusinessDayStatus::Open;
    }

    public function cutoff(CutoffKind $kind): Carbon
    {
        return $this->getAttribute($kind->column());
    }

    /** Clé de la journée dans les URL et les canaux temps réel. */
    public function dayKey(): string
    {
        return $this->day->format('Y-m-d');
    }
}
