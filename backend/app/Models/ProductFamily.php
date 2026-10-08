<?php

namespace App\Models;

use App\Models\Concerns\LogsReferentialChanges;
use Database\Factories\ProductFamilyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Famille ou gamme de produits, éventuellement rattachée à une famille
 * parente (EF-PRD-01).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property ?int $parent_id
 * @property int $sort_order
 */
class ProductFamily extends Model
{
    /** @use HasFactory<ProductFamilyFactory> */
    use HasFactory, LogsReferentialChanges;

    protected $fillable = ['code', 'name', 'parent_id', 'sort_order'];

    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<ProductFamily, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'parent_id');
    }

    /** @return HasMany<ProductFamily, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(ProductFamily::class, 'parent_id');
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'family_id');
    }

    protected static function auditDescriptions(): array
    {
        return ['created' => 'Famille de produits créée', 'updated' => 'Famille de produits modifiée', 'deleted' => 'Famille de produits supprimée'];
    }
}
