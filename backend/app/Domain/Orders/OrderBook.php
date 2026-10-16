<?php

namespace App\Domain\Orders;

use App\Domain\Limits\LimitBook;
use App\Domain\Settings\Settings;
use App\Models\ClientLimit;
use App\Models\Product;
use App\Models\RegionDay;
use App\Models\RegionQuota;

/**
 * Disponibilités d'un client pour une journée régionale (§5.1, RG-C03,
 * RG-C09) : pour chaque produit commandable, quota et validé de la région,
 * borne publiée du client, quantité engagée E(c,p) et disponible D(c,p).
 * Lecture seule : rien n'est réservé (H-05).
 *
 * Produit commandable (I-44) : quota publié > 0 et produit actif ce jour-là
 * (LimitBook::availableQuotaRows), prix en vigueur ; jamais une borne 0
 * (produit interdit, RG-B01) ni un produit sans borne en politique
 * OBLIGATOIRE (RG-B04). Les brouillons (quotas, bornes) sont ignorés.
 */
final class OrderBook
{
    public function __construct(
        private readonly LimitBook $limits,
        private readonly OrderUsage $usage,
        private readonly Settings $settings,
    ) {}

    /**
     * @return array<int, array{quota: int, region_validated: int, region_remaining: int, limit: ?int, engaged: int, available: int, sold_out: bool, price_ht: string}> produit → disponibilités
     */
    public function availability(RegionDay $regionDay, int $clientId, ?int $exceptOrderId = null): array
    {
        $quotas = $this->limits->availableQuotaRows($regionDay);
        if ($quotas->isEmpty()) {
            return [];
        }
        $day = $regionDay->businessDay->day;
        $limitRequired = $this->settings->get('limits.no_limit_policy') === 'REQUIRED';
        $limits = ClientLimit::query()
            ->where('region_day_id', $regionDay->id)
            ->where('client_id', $clientId)
            ->whereIn('product_id', $quotas->keys())
            ->pluck('max_qty', 'product_id');
        $usage = $this->usage->forClient($regionDay->business_day_id, $clientId, $exceptOrderId);
        $prices = Product::query()
            ->whereKey($quotas->keys())
            ->withPriceOn($day)
            ->pluck('current_price_ht', 'id');

        $rows = [];
        /** @var RegionQuota $quota */
        foreach ($quotas as $productId => $quota) {
            $limit = $limits->get($productId);
            $limit = $limit === null ? null : (int) $limit;
            $price = $prices->get($productId);
            if ($price === null || $limit === 0 || ($limit === null && $limitRequired)) {
                continue;
            }
            $engaged = ($usage[$productId]['pending'] ?? 0) + ($usage[$productId]['validated'] ?? 0);
            $remaining = (int) $quota->quantity - (int) $quota->validated_qty;
            $available = Availability::compute($limit, $engaged, $remaining, $limitRequired);
            $rows[(int) $productId] = [
                'quota' => (int) $quota->quantity,
                'region_validated' => (int) $quota->validated_qty,
                'region_remaining' => $remaining,
                'limit' => $limit,
                'engaged' => $engaged,
                'available' => $available,
                'sold_out' => $available === 0,
                'price_ht' => (string) $price,
            ];
        }

        return $rows;
    }
}
