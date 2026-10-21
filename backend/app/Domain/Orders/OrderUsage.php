<?php

namespace App\Domain\Orders;

use App\Domain\Limits\ClientUsage;
use App\Models\BusinessDay;
use App\Models\RegionDay;
use Illuminate\Support\Facades\DB;

/**
 * Consommation réelle des clients (§5.1), lue sur les commandes :
 * S(c,p) = quantités demandées des commandes envoyées, V(c,p) = quantités
 * validées des commandes validées et suivantes. Les commandes rejetées,
 * annulées et expirées ne comptent pas.
 */
final class OrderUsage implements ClientUsage
{
    /**
     * Une seule requête groupée, sur la journée et la région de la journée
     * régionale (région figée à l'envoi).
     */
    public function forRegionDay(RegionDay $regionDay): array
    {
        $usage = [];
        foreach ($this->rows($regionDay->business_day_id, ['orders.region_id' => $regionDay->region_id]) as $row) {
            $usage[(int) $row->client_id][(int) $row->product_id] = [
                'pending' => (int) $row->pending,
                'validated' => (int) $row->validated,
            ];
        }

        return $usage;
    }

    /**
     * Consommation d'un client pour une journée, toutes régions confondues,
     * hors commande en cours de modification (E(c,p) l'exclut, §5.1).
     *
     * @return array<int, array{pending: int, validated: int}> produit → S et V
     */
    public function forClient(BusinessDay|int $day, int $clientId, ?int $exceptOrderId = null): array
    {
        $usage = [];
        $where = ['orders.client_id' => $clientId];
        foreach ($this->rows($day instanceof BusinessDay ? $day->id : $day, $where, $exceptOrderId) as $row) {
            $usage[(int) $row->product_id] = ['pending' => (int) $row->pending, 'validated' => (int) $row->validated];
        }

        return $usage;
    }

    /**
     * @param  array<string, int>  $where
     * @return iterable<object{client_id: int, product_id: int, pending: int|string, validated: int|string}>
     */
    private function rows(int $businessDayId, array $where, ?int $exceptOrderId = null): iterable
    {
        $pending = OrderStatus::values(OrderStatus::pending());
        $validated = OrderStatus::values(OrderStatus::validated());
        $in = fn (array $values) => implode(',', array_map(fn (string $v) => "'{$v}'", $values));

        return DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->where('orders.business_day_id', $businessDayId)
            ->where($where)
            ->whereIn('orders.status', OrderStatus::values(OrderStatus::counted()))
            ->when($exceptOrderId !== null, fn ($query) => $query->where('orders.id', '!=', $exceptOrderId))
            ->groupBy('orders.client_id', 'order_lines.product_id')
            ->select('orders.client_id', 'order_lines.product_id')
            ->selectRaw('SUM(CASE WHEN orders.status IN ('.$in($pending).') THEN order_lines.requested_qty ELSE 0 END) AS pending')
            ->selectRaw('SUM(CASE WHEN orders.status IN ('.$in($validated).') THEN COALESCE(order_lines.validated_qty, 0) ELSE 0 END) AS validated')
            ->get();
    }
}
