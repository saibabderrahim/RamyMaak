<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * État d'anti-saturation des push d'un utilisateur pour un groupe (EF-NOT-02,
 * contrat du lot 8 §2.4).
 *
 * @property int $id
 * @property int $user_id
 * @property string $group
 * @property Carbon|null $last_sent_at
 * @property int $pending
 * @property string|null $last_title
 * @property Carbon|null $flush_at
 */
class PushThrottle extends Model
{
    protected $fillable = ['user_id', 'group', 'last_sent_at', 'pending', 'last_title', 'flush_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'pending' => 'integer',
            'last_sent_at' => 'datetime',
            'flush_at' => 'datetime',
        ];
    }
}
