<?php

namespace App\Models;

use App\Casts\BusinessDate;
use App\Models\Concerns\LogsReferentialChanges;
use Carbon\CarbonImmutable;
use Database\Factories\ProductPriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prix HT d'une UV, en DA, à partir d'une date d'effet (RG-P01). Un seul
 * prix en vigueur à une date donnée : celui dont la date d'effet est la plus
 * récente sans dépasser cette date. Géré par PriceBook.
 *
 * @property int $id
 * @property int $product_id
 * @property string $price_ht
 * @property CarbonImmutable $valid_from
 * @property ?CarbonImmutable $valid_to
 * @property ?int $created_by
 */
class ProductPrice extends Model
{
    /** @use HasFactory<ProductPriceFactory> */
    use HasFactory, LogsReferentialChanges;

    protected $fillable = ['product_id', 'price_ht', 'valid_from', 'valid_to', 'created_by'];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'price_ht' => 'decimal:2',
            'valid_from' => BusinessDate::class,
            'valid_to' => BusinessDate::class,
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function auditDescriptions(): array
    {
        return ['created' => 'Prix créé', 'updated' => 'Prix modifié', 'deleted' => 'Prix supprimé'];
    }
}
