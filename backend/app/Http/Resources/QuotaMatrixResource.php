<?php

namespace App\Http\Resources;

use App\Models\BusinessDay;
use App\Models\Product;
use App\Models\RegionDay;
use App\Models\RegionQuota;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Matrice des quotas d'une journée (EF-ADV-02) : produits en lignes, régions
 * en colonnes, cellules { quantity (Q publié), draft (brouillon ou null),
 * validated (V) } indexées par produit puis par région.
 *
 * - Colonnes : régions actives du périmètre du lecteur (RG-S05), par code.
 * - Lignes : produits actifs, et produits désactivés depuis qui gardent un
 *   quota publié > 0 ce jour-là dans une région visible (lecture seule),
 *   triés par famille (ordre, code) puis par ordre et code article.
 * - Cellules : seules celles qui existent en base.
 *
 * Nombre de requêtes borné, quelle que soit la taille de la matrice.
 *
 * @mixin BusinessDay
 */
class QuotaMatrixResource extends JsonResource
{
    /**
     * @param  Collection<int, RegionDay>  $regionDays  colonnes, par code de région
     * @param  Collection<int, Product>  $products  lignes, dans l'ordre d'affichage
     * @param  Collection<int, RegionQuota>  $quotas  cellules existantes de ces lignes et colonnes
     */
    public function __construct(
        BusinessDay $day,
        private readonly Collection $regionDays,
        private readonly Collection $products,
        private readonly Collection $quotas,
    ) {
        parent::__construct($day);
    }

    /** Matrice de la journée vue par l'utilisateur (son périmètre). */
    public static function for(BusinessDay $day, User $user): self
    {
        $regionDays = DayResource::visibleRegionDays($day, $user);
        $regionDayIds = $regionDays->pluck('id')->all();

        $products = self::rows($regionDayIds)->get();
        $productIds = $products->pluck('id')->flip();

        $quotas = RegionQuota::query()
            ->whereIn('region_day_id', $regionDayIds)
            ->get(['region_day_id', 'product_id', 'quantity', 'draft_quantity', 'validated_qty'])
            ->filter(fn (RegionQuota $quota) => $productIds->has($quota->product_id))
            ->values();

        return new self($day, $regionDays, $products, $quotas);
    }

    /**
     * Produits de la matrice, dans l'ordre d'affichage des lignes.
     *
     * @param  list<int>  $regionDayIds
     * @return Builder<Product>
     */
    public static function rows(array $regionDayIds): Builder
    {
        return Product::query()
            ->select(['products.id', 'products.sku', 'products.name', 'products.short_name', 'products.family_id', 'products.sales_unit_label', 'products.is_active'])
            ->join('product_families', 'product_families.id', '=', 'products.family_id')
            ->with('family:id,code,name')
            ->where(fn (Builder $query) => $query
                ->where('products.is_active', true)
                // Désactivé depuis la publication : la ligne reste visible, en lecture seule.
                ->orWhereExists(fn ($published) => $published
                    ->selectRaw('1')
                    ->from('region_quotas')
                    ->whereColumn('region_quotas.product_id', 'products.id')
                    ->whereIn('region_quotas.region_day_id', $regionDayIds)
                    ->where('region_quotas.quantity', '>', 0)))
            ->orderBy('product_families.sort_order')
            ->orderBy('product_families.code')
            ->orderBy('products.sort_order')
            ->orderBy('products.sku');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Cellules dans l'ordre des lignes, puis des colonnes.
        $quotas = $this->quotas->groupBy('product_id')->map(fn (Collection $byProduct) => $byProduct->keyBy('region_day_id'));
        $cells = [];
        foreach ($this->products as $product) {
            foreach ($this->regionDays as $regionDay) {
                $quota = $quotas->get($product->id)?->get($regionDay->id);
                if ($quota !== null) {
                    $cells[$product->id][$regionDay->region_id] = [
                        'quantity' => $quota->quantity,
                        'draft' => $quota->draft_quantity,
                        'validated' => $quota->validated_qty,
                    ];
                }
            }
        }

        return [
            'day' => new DayResource($this->resource),
            'regions' => $this->regionDays->map(fn (RegionDay $regionDay) => [
                'id' => $regionDay->region->id,
                'code' => $regionDay->region->code,
                'name' => $regionDay->region->name,
                'status' => $regionDay->status->value,
                'quota_version' => $regionDay->quota_version,
                'pending_drafts' => (int) $regionDay->pending_drafts_count,
            ])->values()->all(),
            'products' => $this->products->map(fn (Product $product) => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'short_name' => $product->short_name,
                'family' => $product->family === null ? null : [
                    'id' => $product->family->id,
                    'code' => $product->family->code,
                    'name' => $product->family->name,
                ],
                'sales_unit_label' => $product->sales_unit_label,
                'is_active' => (bool) $product->is_active,
            ])->values()->all(),
            // Objets JSON (et non listes), même vides : clés = identifiants.
            'cells' => (object) array_map(fn (array $byRegion) => (object) $byRegion, $cells),
        ];
    }
}
