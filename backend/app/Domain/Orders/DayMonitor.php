<?php

namespace App\Domain\Orders;

use App\Domain\Days\CutoffKind;
use App\Domain\Days\RegionDayStatus;
use App\Domain\Limits\LimitBook;
use App\Models\BusinessDay;
use App\Models\Product;
use App\Models\RegionDay;
use App\Models\RegionQuota;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Suivi de la journée de l'ADV et de l'Admin (EF-ADV-03, §11.2 ; contrat du
 * lot 6, §6, I-89) : pour chaque région du périmètre du lecteur, statut,
 * clients notifiés et ayant commandé, commandes par statut, dernière
 * validation, alertes de retard ; pour chaque produit, quota Q, validé V,
 * en attente (commandes envoyées) et reste R = Q − V, par région et au
 * total, avec les régions épuisées (Q > 0 et R = 0).
 *
 * Lecture sans verrou, en un nombre de requêtes constant quel que soit le
 * nombre de commandes : une requête groupée par agrégat (commandes par
 * région et statut, clients distincts, quantités en attente, quotas,
 * produits), plus la lecture des destinataires de N-03 pour chaque région
 * dont la répartition est envoyée. Les alertes (retard de répartition, retard
 * de validation) sont visuelles ; N-13 arrive au lot 7.
 */
final class DayMonitor
{
    /** Statuts comptés par région et au total (Livrée, V2, exclue). */
    private const STATUSES = [
        OrderStatus::Submitted, OrderStatus::Validated, OrderStatus::InPreparation, OrderStatus::Prepared,
        OrderStatus::Shipped, OrderStatus::Rejected, OrderStatus::Cancelled, OrderStatus::Expired,
    ];

    public const DISTRIBUTION_LATE = 'DISTRIBUTION_LATE';

    public const VALIDATION_LATE = 'VALIDATION_LATE';

    public function __construct(
        private readonly LimitBook $limits,
        private readonly ValidationWindow $window,
    ) {}

    /**
     * Forme de la réponse (`DayMonitoring`, contrat du lot 6, §6).
     * `$regionDays` : journées régionales du périmètre du lecteur, par code
     * de région, avec `businessDay` (DayResource::visibleRegionDays).
     *
     * @param  Collection<int, RegionDay>  $regionDays
     * @return array<string, mixed>
     */
    public function snapshot(BusinessDay $day, Collection $regionDays, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $regionDays->loadMissing('region.depot');
        $regionIds = $regionDays->pluck('region_id')->all();
        $regionDayIds = $regionDays->pluck('id')->all();

        $counts = $this->orderCounts($day, $regionIds);
        $ordered = $this->orderedClients($day, $regionIds);
        $pending = $this->pendingQuantities($day, $regionIds);
        $quotas = $regionDayIds === [] ? collect() : RegionQuota::query()
            ->whereIn('region_day_id', $regionDayIds)
            ->where('quantity', '>', 0)
            ->get(['region_day_id', 'product_id', 'quantity', 'validated_qty']);

        // Cellules région → produit : quota publié ou quantités en attente.
        $regionOf = $regionDays->pluck('region_id', 'id');
        /** @var array<int, array<int, array{quota: int, validated: int, pending: int}>> $cells */
        $cells = array_fill_keys($regionIds, []);
        foreach ($quotas as $quota) {
            $cells[$regionOf[$quota->region_day_id]][$quota->product_id] = ['quota' => $quota->quantity, 'validated' => $quota->validated_qty, 'pending' => 0];
        }
        foreach ($pending as $regionId => $byProduct) {
            foreach ($byProduct as $productId => $quantity) {
                $cells[$regionId][$productId] ??= ['quota' => 0, 'validated' => 0, 'pending' => 0];
                $cells[$regionId][$productId]['pending'] = $quantity;
            }
        }

        $regions = [];
        $totals = array_fill_keys(OrderStatus::values(self::STATUSES), 0);
        $notifiedTotal = 0;
        $orderedTotal = 0;
        foreach ($regionDays as $regionDay) {
            $regionId = $regionDay->region_id;
            $orders = $counts[$regionId]['orders'] ?? array_fill_keys(OrderStatus::values(self::STATUSES), 0);
            $notified = $regionDay->isDistributionPublished() ? count($this->limits->openingRecipients($regionDay)) : 0;
            $clients = $ordered[$regionId] ?? 0;
            foreach ($orders as $status => $count) {
                $totals[$status] += $count;
            }
            $notifiedTotal += $notified;
            $orderedTotal += $clients;

            $validation = $this->window->state($day, $regionDay, false, $now);
            $regions[] = [
                'region' => [
                    'id' => $regionDay->region->id,
                    'code' => $regionDay->region->code,
                    'name' => $regionDay->region->name,
                    'depot' => $regionDay->region->depot === null ? null : [
                        'id' => $regionDay->region->depot->id,
                        'code' => $regionDay->region->depot->code,
                        'name' => $regionDay->region->depot->name,
                    ],
                ],
                'status' => $regionDay->status->value,
                'status_label' => $regionDay->status->label(),
                'quota_published_at' => $regionDay->quota_published_at?->toIso8601String(),
                'distribution_published_at' => $regionDay->distribution_published_at?->toIso8601String(),
                'cutoffs' => [
                    'distribution' => BusinessClock::toLocal($regionDay->effectiveCutoff(CutoffKind::Distribution))->format('H:i'),
                    'ordering' => BusinessClock::toLocal($regionDay->effectiveCutoff(CutoffKind::Ordering))->format('H:i'),
                    'validation' => $validation->cutoff(),
                ],
                'clients' => ['notified' => $notified, 'ordered' => $clients],
                'orders' => $orders,
                'last_validated_at' => ($counts[$regionId]['last_validated_at'] ?? null)?->toIso8601String(),
                'alerts' => $this->alerts($day, $regionDay, $orders[OrderStatus::Submitted->value], $validation->passed, $now),
            ];
        }

        return [
            'day' => $day->dayKey(),
            'status' => $day->status->value,
            'cutoffs' => $this->dayCutoffs($day),
            'generated_at' => $now->toIso8601String(),
            'totals' => ['orders' => $totals, 'clients' => ['notified' => $notifiedTotal, 'ordered' => $orderedTotal]],
            'regions' => $regions,
            'products' => $this->products($regionDays, $cells),
            // Objets JSON, même vides : cells[région][produit].
            'cells' => (object) array_map(fn (array $byProduct) => (object) $byProduct, $cells),
        ];
    }

    /**
     * Alertes visuelles d'une région d'une journée ouverte : répartition non
     * envoyée à l'heure limite (quotas publiés), commandes encore envoyées
     * après l'heure limite de validation.
     *
     * @return list<string>
     */
    private function alerts(BusinessDay $day, RegionDay $regionDay, int $submitted, bool $validationPassed, CarbonInterface $now): array
    {
        if (! $day->isOpen() || $regionDay->status === RegionDayStatus::Closed) {
            return [];
        }

        $alerts = [];
        if ($regionDay->isQuotaPublished()
            && ! $regionDay->isDistributionPublished()
            && $now->greaterThanOrEqualTo($regionDay->effectiveCutoff(CutoffKind::Distribution))) {
            $alerts[] = self::DISTRIBUTION_LATE;
        }
        if ($submitted > 0 && $validationPassed) {
            $alerts[] = self::VALIDATION_LATE;
        }

        return $alerts;
    }

    /**
     * Heures limites de la journée (« HH:MM », heure d'Alger).
     *
     * @return array<string, string>
     */
    private function dayCutoffs(BusinessDay $day): array
    {
        $cutoffs = [];
        foreach (CutoffKind::chronological() as $kind) {
            $cutoffs[strtolower($kind->value)] = BusinessClock::toLocal($day->cutoff($kind))->format('H:i');
        }

        return $cutoffs;
    }

    /**
     * Commandes par région et par statut, et dernière validation de la région
     * (heure de décision des commandes validées ou annulées après validation),
     * en une requête groupée.
     *
     * @param  list<int>  $regionIds
     * @return array<int, array{orders: array<string, int>, last_validated_at: ?CarbonImmutable}>
     */
    private function orderCounts(BusinessDay $day, array $regionIds): array
    {
        if ($regionIds === []) {
            return [];
        }
        $rows = DB::table('orders')
            ->where('business_day_id', $day->id)
            ->whereIn('region_id', $regionIds)
            ->groupBy('region_id', 'status')
            ->selectRaw('region_id, status, COUNT(*) AS aggregate, MAX(decided_at) AS last_decided_at')
            ->get();

        $neverValidated = [OrderStatus::Rejected->value];
        $result = [];
        foreach ($rows as $row) {
            $region = (int) $row->region_id;
            $result[$region]['orders'] ??= array_fill_keys(OrderStatus::values(self::STATUSES), 0);
            $result[$region]['last_validated_at'] ??= null;
            if (array_key_exists($row->status, $result[$region]['orders'])) {
                $result[$region]['orders'][$row->status] = (int) $row->aggregate;
            }
            if ($row->last_decided_at !== null && ! in_array($row->status, $neverValidated, true)) {
                $decided = CarbonImmutable::parse($row->last_decided_at, 'UTC');
                if ($result[$region]['last_validated_at'] === null || $decided->greaterThan($result[$region]['last_validated_at'])) {
                    $result[$region]['last_validated_at'] = $decided;
                }
            }
        }

        return $result;
    }

    /**
     * Clients distincts ayant une commande comptée (Envoyée ou Validée et
     * suivantes), par région.
     *
     * @param  list<int>  $regionIds
     * @return array<int, int>
     */
    private function orderedClients(BusinessDay $day, array $regionIds): array
    {
        if ($regionIds === []) {
            return [];
        }

        return DB::table('orders')
            ->where('business_day_id', $day->id)
            ->whereIn('region_id', $regionIds)
            ->whereIn('status', OrderStatus::values(OrderStatus::counted()))
            ->groupBy('region_id')
            ->selectRaw('region_id, COUNT(DISTINCT client_id) AS aggregate')
            ->pluck('aggregate', 'region_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * Quantités demandées des commandes envoyées, par région et produit.
     *
     * @param  list<int>  $regionIds
     * @return array<int, array<int, int>>
     */
    private function pendingQuantities(BusinessDay $day, array $regionIds): array
    {
        if ($regionIds === []) {
            return [];
        }
        $rows = DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->where('orders.business_day_id', $day->id)
            ->whereIn('orders.region_id', $regionIds)
            ->whereIn('orders.status', OrderStatus::values(OrderStatus::pending()))
            ->groupBy('orders.region_id', 'order_lines.product_id')
            ->selectRaw('orders.region_id AS region_id, order_lines.product_id AS product_id, SUM(order_lines.requested_qty) AS aggregate')
            ->get();

        $pending = [];
        foreach ($rows as $row) {
            $pending[(int) $row->region_id][(int) $row->product_id] = (int) $row->aggregate;
        }

        return $pending;
    }

    /**
     * Produits ayant un quota ou des quantités en attente, par famille puis
     * ordre (comme la matrice des quotas), avec leurs totaux et leurs régions
     * épuisées (Q > 0 et R = 0).
     *
     * @param  Collection<int, RegionDay>  $regionDays
     * @param  array<int, array<int, array{quota: int, validated: int, pending: int}>>  $cells
     * @return list<array<string, mixed>>
     */
    private function products(Collection $regionDays, array $cells): array
    {
        $productIds = [];
        foreach ($cells as $byProduct) {
            foreach (array_keys($byProduct) as $productId) {
                $productIds[$productId] = $productId;
            }
        }
        if ($productIds === []) {
            return [];
        }

        $products = Product::query()
            ->select(['products.id', 'products.sku', 'products.short_name', 'products.family_id'])
            ->join('product_families', 'product_families.id', '=', 'products.family_id')
            ->with('family:id,name')
            ->whereIn('products.id', array_values($productIds))
            ->orderBy('product_families.sort_order')
            ->orderBy('product_families.code')
            ->orderBy('products.sort_order')
            ->orderBy('products.sku')
            ->get();

        return $products->map(function (Product $product) use ($regionDays, $cells): array {
            $quota = $validated = $pending = 0;
            $soldOut = [];
            foreach ($regionDays as $regionDay) {
                $cell = $cells[$regionDay->region_id][$product->id] ?? null;
                if ($cell === null) {
                    continue;
                }
                $quota += $cell['quota'];
                $validated += $cell['validated'];
                $pending += $cell['pending'];
                if ($cell['quota'] > 0 && $cell['quota'] - $cell['validated'] === 0) {
                    $soldOut[] = $regionDay->region_id;
                }
            }

            return [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'short_name' => $product->short_name,
                'family' => ['id' => $product->family->id, 'name' => $product->family->name],
                'quota' => $quota,
                'validated' => $validated,
                'pending' => $pending,
                'remaining' => $quota - $validated,
                'sold_out_regions' => $soldOut,
            ];
        })->values()->all();
    }
}
