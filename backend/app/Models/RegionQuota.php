<?php

namespace App\Models;

use Database\Factories\RegionQuotaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quota d'un produit pour une région et une journée (RG-Q01) : valeur publiée
 * Q(r,p), brouillon en attente (RG-Q03) et compteur validé V(r,p) (§5.8).
 *
 * @property int $id
 * @property int $region_day_id
 * @property int $product_id
 * @property int $quantity
 * @property ?int $draft_quantity
 * @property int $validated_qty
 * @property ?int $updated_by
 */
class RegionQuota extends Model
{
    /** @use HasFactory<RegionQuotaFactory> */
    use HasFactory;

    // validated_qty n'est jamais affecté en masse : seul le registre des
    // validations le modifie, par mise à jour conditionnelle (§5.8).
    protected $fillable = ['region_day_id', 'product_id', 'quantity', 'draft_quantity', 'updated_by'];

    protected $attributes = [
        'quantity' => 0,
        'validated_qty' => 0,
    ];

    protected function casts(): array
    {
        return [
            'region_day_id' => 'integer',
            'product_id' => 'integer',
            'quantity' => 'integer',
            'draft_quantity' => 'integer',
            'validated_qty' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /** @return BelongsTo<RegionDay, $this> */
    public function regionDay(): BelongsTo
    {
        return $this->belongsTo(RegionDay::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function hasDraft(): bool
    {
        return $this->draft_quantity !== null;
    }
}
