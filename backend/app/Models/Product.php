<?php

namespace App\Models;

use App\Casts\BusinessDate;
use App\Models\Concerns\LogsReferentialChanges;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Produit du catalogue (EF-PRD-01). Quantités exprimées en unité de vente
 * (UV, H-01) ; prix par UV dans product_prices (RG-P01).
 *
 * @property int $id
 * @property string $sku
 * @property string $name
 * @property string $short_name
 * @property int $family_id
 * @property ?string $format
 * @property string $sales_unit_label
 * @property int $units_per_sales_unit
 * @property ?int $sales_units_per_pallet
 * @property ?string $ean
 * @property string $vat_rate
 * @property bool $is_active
 * @property ?CarbonImmutable $deactivate_on désactivation programmée (RG-Q07)
 * @property bool $is_new
 * @property bool $is_featured
 * @property int $sort_order
 */
class Product extends Model implements HasMedia
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, InteractsWithMedia, LogsReferentialChanges;

    /** Collection des photos : 1 principale (la première) + 4 secondaires (EF-PRD-02). */
    public const PHOTOS = 'photos';

    public const MAX_PHOTOS = 5;

    /** Variantes WebP générées à l'envoi d'une photo (§9.8), largeur maximale en px. */
    public const PHOTO_SIZES = ['thumb' => 200, 'medium' => 600, 'large' => 1200];

    protected $fillable = [
        'sku', 'name', 'short_name', 'family_id', 'format', 'sales_unit_label',
        'units_per_sales_unit', 'sales_units_per_pallet', 'ean', 'vat_rate',
        'is_active', 'is_new', 'is_featured', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'family_id' => 'integer',
            'units_per_sales_unit' => 'integer',
            'sales_units_per_pallet' => 'integer',
            'vat_rate' => 'decimal:2',
            'is_active' => 'boolean',
            'deactivate_on' => BusinessDate::class,
            'is_new' => 'boolean',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<ProductFamily, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'family_id');
    }

    /** @return HasMany<ProductPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class)->orderBy('valid_from');
    }

    /**
     * Produits actifs le jour donné (RG-Q02) : actifs, sans désactivation
     * programmée à cette date ou avant (RG-Q07).
     *
     * @param  Builder<Product>  $query
     */
    public function scopeActiveOn(Builder $query, CarbonInterface $day): void
    {
        $query->where('products.is_active', true)
            ->where(fn (Builder $query) => $query
                ->whereNull('products.deactivate_on')
                ->orWhere('products.deactivate_on', '>', $day->format('Y-m-d')));
    }

    /** Actif le jour donné (RG-Q02, RG-Q07) : voir scopeActiveOn. */
    public function isActiveOn(CarbonInterface $day): bool
    {
        return $this->is_active
            && ($this->deactivate_on === null || $this->deactivate_on->format('Y-m-d') > $day->format('Y-m-d'));
    }

    /**
     * Ajoute à la requête le prix HT en vigueur le jour donné
     * (`current_price_ht`, NULL si aucun prix).
     *
     * @param  Builder<Product>  $query
     */
    public function scopeWithPriceOn(Builder $query, CarbonInterface $day): void
    {
        if ($query->getQuery()->columns === null) {
            $query->select('products.*');
        }

        $query->addSelect(['current_price_ht' => ProductPrice::query()
            ->select('price_ht')
            ->whereColumn('product_id', 'products.id')
            ->where('valid_from', '<=', $day->format('Y-m-d'))
            ->orderByDesc('valid_from')
            ->limit(1),
        ]);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::PHOTOS)
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        foreach (self::PHOTO_SIZES as $name => $width) {
            $this->addMediaConversion($name)
                ->format('webp')
                ->fit(Fit::Max, $width, $width)
                ->performOnCollections(self::PHOTOS)
                ->nonQueued();
        }
    }

    protected static function auditDescriptions(): array
    {
        return ['created' => 'Produit créé', 'updated' => 'Produit modifié', 'deleted' => 'Produit supprimé'];
    }
}
