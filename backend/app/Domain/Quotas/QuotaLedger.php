<?php

namespace App\Domain\Quotas;

use App\Domain\Orders\OrderStatus;
use App\Models\RegionQuota;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Registre des validations (§5.8, §9.4, ENF-15) : **seul** écrivain du
 * compteur V(r,p) (`region_quotas.validated_qty`). La validation le réserve
 * (OrderDecisions), l'annulation exceptionnelle de l'ADV le restitue
 * (AdvOrders), la réconciliation nocturne le compare aux lignes de commande.
 *
 * Toujours dans la transaction de l'appelant, après ses verrous (client,
 * journée, commande, I-67), par produit croissant. Une quantité 0 ne touche
 * jamais le compteur (§5.8 : MySQL compterait 0 ligne modifiée).
 */
final class QuotaLedger
{
    /**
     * Compteurs verrouillés (FOR UPDATE), par produit croissant : base du
     * contrôle préalable de la validation (quota, validé). Un produit sans
     * ligne de quota n'y figure pas (Q = 0).
     *
     * @param  list<int>  $productIds
     * @return Collection<int, RegionQuota> produit → compteur
     */
    public function lock(int $regionDayId, array $productIds): Collection
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return collect();
        }

        return RegionQuota::query()
            ->where('region_day_id', $regionDayId)
            ->whereIn('product_id', $productIds)
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get(['id', 'region_day_id', 'product_id', 'quantity', 'validated_qty'])
            ->keyBy('product_id');
    }

    /**
     * V(r,p) += q, à condition de rester sous Q(r,p) (§5.8 étape 2) :
     * `UPDATE … SET validated_qty = validated_qty + :q WHERE … AND
     * validated_qty + :q <= quantity`. 0 ligne modifiée : QuotaExceeded,
     * l'appelant annule toute sa transaction.
     *
     * @param  array<int, int>  $quantities  produit → quantité validée
     *
     * @throws QuotaExceeded
     */
    public function reserve(int $regionDayId, array $quantities): void
    {
        foreach ($this->positive($quantities) as $productId => $quantity) {
            $updated = $this->counter($regionDayId, $productId)
                ->whereRaw('validated_qty + ? <= quantity', [$quantity])
                ->increment('validated_qty', $quantity);
            if ($updated === 0) {
                throw new QuotaExceeded($regionDayId, $productId, $quantity);
            }
        }
    }

    /**
     * V(r,p) −= q (annulation exceptionnelle de l'ADV, RG-V07), même après la
     * clôture (H-06). Jamais sous 0 ni ramené silencieusement à 0 : un
     * compteur plus petit que la quantité restituée est une dérive, signalée
     * par une exception (annulation de la transaction, erreur 500).
     *
     * @param  array<int, int>  $quantities  produit → quantité validée restituée
     *
     * @throws LogicException dérive du compteur
     */
    public function release(int $regionDayId, array $quantities): void
    {
        foreach ($this->positive($quantities) as $productId => $quantity) {
            $updated = $this->counter($regionDayId, $productId)
                ->where('validated_qty', '>=', $quantity)
                ->decrement('validated_qty', $quantity);
            if ($updated === 0) {
                throw new LogicException("Compteur validé incohérent : journée régionale {$regionDayId}, produit {$productId}, restitution de {$quantity}.");
            }
        }
    }

    /**
     * Réconciliation (§5.8, §9.7, I-93), en lecture seule et en une
     * requête : chaque compteur V(r,p) des journées du `$from` au `$to`
     * (bornes comprises) comparé à la somme des quantités validées des
     * commandes Validées et suivantes de la journée et de la région (région
     * figée à l'envoi). Rend les seuls écarts, y compris les lignes validées
     * sans compteur (`counter` null). Jamais de correction.
     *
     * @return list<array{business_day: string, business_day_id: int, region_id: int, product_id: int, counter: ?int, recomputed: int}>
     */
    public function reconcile(CarbonInterface $from, CarbonInterface $to): array
    {
        $sums = $this->validatedSums();
        $range = [$from->format('Y-m-d'), $to->format('Y-m-d')];

        $counters = DB::table('region_quotas')
            ->join('region_days', 'region_days.id', '=', 'region_quotas.region_day_id')
            ->join('business_days', 'business_days.id', '=', 'region_days.business_day_id')
            ->leftJoinSub($sums, 'sums', fn ($join) => $join
                ->on('sums.business_day_id', '=', 'region_days.business_day_id')
                ->on('sums.region_id', '=', 'region_days.region_id')
                ->on('sums.product_id', '=', 'region_quotas.product_id'))
            ->whereBetween('business_days.day', $range)
            ->whereRaw('region_quotas.validated_qty <> COALESCE(sums.recomputed, 0)')
            ->select('business_days.day', 'region_days.business_day_id', 'region_days.region_id', 'region_quotas.product_id')
            ->selectRaw('region_quotas.validated_qty AS counter, COALESCE(sums.recomputed, 0) AS recomputed');

        $orphans = DB::table('business_days')
            ->joinSub($sums, 'sums', 'sums.business_day_id', '=', 'business_days.id')
            ->leftJoin('region_days', fn ($join) => $join
                ->on('region_days.business_day_id', '=', 'sums.business_day_id')
                ->on('region_days.region_id', '=', 'sums.region_id'))
            ->leftJoin('region_quotas', fn ($join) => $join
                ->on('region_quotas.region_day_id', '=', 'region_days.id')
                ->on('region_quotas.product_id', '=', 'sums.product_id'))
            ->whereBetween('business_days.day', $range)
            ->whereNull('region_quotas.id')
            ->where('sums.recomputed', '>', 0)
            ->select('business_days.day', 'sums.business_day_id', 'sums.region_id', 'sums.product_id')
            ->selectRaw('NULL AS counter, sums.recomputed AS recomputed');

        return $counters->unionAll($orphans)
            ->orderBy('day')
            ->orderBy('region_id')
            ->orderBy('product_id')
            ->get()
            ->map(fn (object $row) => [
                'business_day' => substr((string) $row->day, 0, 10),
                'business_day_id' => (int) $row->business_day_id,
                'region_id' => (int) $row->region_id,
                'product_id' => (int) $row->product_id,
                'counter' => $row->counter === null ? null : (int) $row->counter,
                'recomputed' => (int) $row->recomputed,
            ])
            ->values()
            ->all();
    }

    /**
     * Dépassements d'invariants sur la même période, rapportés comme
     * avertissements (I-93) : I-1, V(r,p) > Q(r,p) (impossible sous MySQL,
     * contrainte CHECK) ; I-2, V(c,p) > B(c,p) pour une borne publiée
     * (V(c,p) des commandes de la région de la borne).
     *
     * @return list<array{invariant: string, business_day: string, region_id: int, product_id: int, client_id: ?int, validated: int, ceiling: int}>
     */
    public function breaches(CarbonInterface $from, CarbonInterface $to): array
    {
        $range = [$from->format('Y-m-d'), $to->format('Y-m-d')];

        $quotas = DB::table('region_quotas')
            ->join('region_days', 'region_days.id', '=', 'region_quotas.region_day_id')
            ->join('business_days', 'business_days.id', '=', 'region_days.business_day_id')
            ->whereBetween('business_days.day', $range)
            ->whereColumn('region_quotas.validated_qty', '>', 'region_quotas.quantity')
            ->get(['business_days.day', 'region_days.region_id', 'region_quotas.product_id', 'region_quotas.validated_qty', 'region_quotas.quantity'])
            ->map(fn (object $row) => [
                'invariant' => 'I-1',
                'business_day' => substr((string) $row->day, 0, 10),
                'region_id' => (int) $row->region_id,
                'product_id' => (int) $row->product_id,
                'client_id' => null,
                'validated' => (int) $row->validated_qty,
                'ceiling' => (int) $row->quantity,
            ]);

        $byClient = DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->whereIn('orders.status', OrderStatus::values(OrderStatus::validated()))
            ->groupBy('orders.business_day_id', 'orders.region_id', 'orders.client_id', 'order_lines.product_id')
            ->select('orders.business_day_id', 'orders.region_id', 'orders.client_id', 'order_lines.product_id')
            ->selectRaw('SUM(COALESCE(order_lines.validated_qty, 0)) AS validated');
        $limits = DB::table('client_limits')
            ->join('region_days', 'region_days.id', '=', 'client_limits.region_day_id')
            ->join('business_days', 'business_days.id', '=', 'region_days.business_day_id')
            ->joinSub($byClient, 'used', fn ($join) => $join
                ->on('used.business_day_id', '=', 'region_days.business_day_id')
                ->on('used.region_id', '=', 'region_days.region_id')
                ->on('used.client_id', '=', 'client_limits.client_id')
                ->on('used.product_id', '=', 'client_limits.product_id'))
            ->whereBetween('business_days.day', $range)
            ->whereNotNull('client_limits.max_qty')
            ->whereColumn('used.validated', '>', 'client_limits.max_qty')
            ->get(['business_days.day', 'region_days.region_id', 'client_limits.product_id', 'client_limits.client_id', 'used.validated', 'client_limits.max_qty'])
            ->map(fn (object $row) => [
                'invariant' => 'I-2',
                'business_day' => substr((string) $row->day, 0, 10),
                'region_id' => (int) $row->region_id,
                'product_id' => (int) $row->product_id,
                'client_id' => (int) $row->client_id,
                'validated' => (int) $row->validated,
                'ceiling' => (int) $row->max_qty,
            ]);

        return $quotas->concat($limits)->values()->all();
    }

    /**
     * Somme des quantités validées par journée, région (figée) et produit.
     */
    private function validatedSums(): Builder
    {
        return DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->whereIn('orders.status', OrderStatus::values(OrderStatus::validated()))
            ->groupBy('orders.business_day_id', 'orders.region_id', 'order_lines.product_id')
            ->select('orders.business_day_id', 'orders.region_id', 'order_lines.product_id')
            ->selectRaw('SUM(COALESCE(order_lines.validated_qty, 0)) AS recomputed');
    }

    private function counter(int $regionDayId, int $productId): Builder
    {
        return DB::table('region_quotas')->where('region_day_id', $regionDayId)->where('product_id', $productId);
    }

    /**
     * Quantités > 0, par produit croissant (ordre des verrous).
     *
     * @param  array<int, int>  $quantities
     * @return array<int, int>
     */
    private function positive(array $quantities): array
    {
        $positive = [];
        foreach ($quantities as $productId => $quantity) {
            if ((int) $quantity > 0) {
                $positive[(int) $productId] = (int) $quantity;
            }
        }
        ksort($positive);

        return $positive;
    }
}
