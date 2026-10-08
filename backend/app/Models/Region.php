<?php

namespace App\Models;

use App\Models\Concerns\LogsReferentialChanges;
use Database\Factories\RegionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Région commerciale (EF-ADM-03).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property ?int $depot_id
 * @property bool $is_active
 */
class Region extends Model
{
    /** @use HasFactory<RegionFactory> */
    use HasFactory, LogsReferentialChanges;

    protected $fillable = ['code', 'name', 'depot_id', 'is_active'];

    protected function casts(): array
    {
        return [
            'depot_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Depot, $this> */
    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    /**
     * Clients rattachés à la région aujourd'hui en base. Pour la région
     * effective à une date donnée (RG-S04), passer par
     * Client::scopeInEffectiveRegions().
     *
     * @return HasMany<Client, $this>
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /** @return HasMany<RegionAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(RegionAssignment::class);
    }

    protected static function auditDescriptions(): array
    {
        return ['created' => 'Région créée', 'updated' => 'Région modifiée', 'deleted' => 'Région supprimée'];
    }
}
