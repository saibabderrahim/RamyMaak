<?php

namespace App\Models;

use App\Domain\Orders\OrderActor;
use App\Domain\Orders\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Historique d'une commande (§6.3) : chaque transition, avec son acteur
 * et son auteur (null = système), sa date et son commentaire. Jamais
 * modifié : l'acteur est celui du moment, pas le rôle actuel du compte.
 *
 * @property int $id
 * @property int $order_id
 * @property ?OrderStatus $from_status
 * @property OrderStatus $to_status
 * @property int $version
 * @property ?int $user_id
 * @property OrderActor $actor
 * @property ?string $comment
 * @property Carbon $created_at
 */
class OrderStatusHistory extends Model
{
    protected $table = 'order_status_history';

    public const UPDATED_AT = null;

    protected $fillable = ['order_id', 'from_status', 'to_status', 'version', 'user_id', 'actor', 'comment', 'created_at'];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('L\'historique d\'une commande n\'est pas modifiable.');
        });
        static::deleting(function (): never {
            throw new LogicException('L\'historique d\'une commande n\'est pas modifiable.');
        });
    }

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'version' => 'integer',
            'user_id' => 'integer',
            'actor' => OrderActor::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
