<?php

namespace App\Models;

use App\Domain\Orders\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Commande d'un client pour une journée (§10.2, §6.2). Exposée par son seul
 * ULID `public_id` (majuscules) ; `number` est le numéro lisible
 * (RMK-AAMMJJ-NNNNN). La région est celle du client à l'envoi, figée.
 *
 * @property int $id
 * @property string $public_id
 * @property string $number
 * @property int $business_day_id
 * @property int $region_id
 * @property int $client_id
 * @property OrderStatus $status
 * @property int $version
 * @property string $client_request_id
 * @property Carbon $submitted_at
 * @property ?int $submitted_by
 * @property ?string $client_comment
 * @property string $total_ht
 * @property string $total_vat
 * @property string $total_ttc
 * @property ?Carbon $decided_at
 * @property ?int $decided_by
 * @property bool $is_adjusted
 * @property ?string $decision_reason_code
 * @property ?string $decision_comment
 * @property ?Carbon $prep_started_at
 * @property ?Carbon $prepared_at
 * @property ?Carbon $shipped_at
 * @property ?string $delivery_note_ref
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $fillable = [
        'public_id', 'number', 'business_day_id', 'region_id', 'client_id', 'status', 'version',
        'client_request_id', 'submitted_at', 'submitted_by', 'client_comment', 'total_ht', 'total_vat', 'total_ttc',
    ];

    protected $attributes = [
        'version' => 1,
        'is_adjusted' => false,
    ];

    protected static function booted(): void
    {
        // ULID en majuscules (Crockford) : HasUlids le mettrait en minuscules.
        static::creating(function (Order $order): void {
            $order->public_id ??= strtoupper((string) Str::ulid());
        });
    }

    protected function casts(): array
    {
        return [
            'business_day_id' => 'integer',
            'region_id' => 'integer',
            'client_id' => 'integer',
            'status' => OrderStatus::class,
            'version' => 'integer',
            'submitted_at' => 'datetime',
            'submitted_by' => 'integer',
            'total_ht' => 'decimal:2',
            'total_vat' => 'decimal:2',
            'total_ttc' => 'decimal:2',
            'decided_at' => 'datetime',
            'decided_by' => 'integer',
            'is_adjusted' => 'boolean',
            'prep_started_at' => 'datetime',
            'prepared_at' => 'datetime',
            'shipped_at' => 'datetime',
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

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return HasMany<OrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('id');
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    /** ULID valide (Crockford, 26 caractères) : le reste répond 404 sans requête. */
    public static function isPublicId(string $value): bool
    {
        return preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $value) === 1;
    }
}
