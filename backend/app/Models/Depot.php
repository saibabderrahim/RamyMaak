<?php

namespace App\Models;

use App\Models\Concerns\LogsReferentialChanges;
use Database\Factories\DepotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Site d'expédition (option, EF-ADM-03).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property ?string $address
 * @property bool $is_active
 */
class Depot extends Model
{
    /** @use HasFactory<DepotFactory> */
    use HasFactory, LogsReferentialChanges;

    protected $fillable = ['code', 'name', 'address', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Region, $this> */
    public function regions(): HasMany
    {
        return $this->hasMany(Region::class);
    }

    protected static function auditDescriptions(): array
    {
        return ['created' => 'Dépôt créé', 'updated' => 'Dépôt modifié', 'deleted' => 'Dépôt supprimé'];
    }
}
