<?php

namespace App\Models;

use App\Models\Concerns\LogsReferentialChanges;
use Database\Factories\ClientCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catégorie de client : grossiste, distributeur… (EF-CLI-01).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 */
class ClientCategory extends Model
{
    /** @use HasFactory<ClientCategoryFactory> */
    use HasFactory, LogsReferentialChanges;

    protected $fillable = ['code', 'name'];

    /** @return HasMany<Client, $this> */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'category_id');
    }

    protected static function auditDescriptions(): array
    {
        return ['created' => 'Catégorie de clients créée', 'updated' => 'Catégorie de clients modifiée', 'deleted' => 'Catégorie de clients supprimée'];
    }
}
