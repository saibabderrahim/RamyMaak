<?php

namespace App\Domain\Documents;

use App\Domain\Orders\OrderStatus;
use App\Models\BusinessDay;
use App\Models\Product;
use App\Models\Region;
use App\Support\BusinessClock;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bon de préparation consolidé (RG-A04, EF-ADV-05, I-84) : quantités
 * **validées** de la journée, lignes à 0 exclues, cumulées par produit
 * (famille, puis ordre) dans chaque groupe, région ou dépôt actuel de la
 * région (« Sans dépôt » sinon), avec le nombre de commandes et les
 * palettes. Pas de détail par client. Calculé en trois requêtes quel que
 * soit le volume (deux agrégats, puis régions et produits des résultats).
 */
final class PickingList
{
    /** Clé du groupe des régions sans dépôt (les identifiants commencent à 1). */
    private const NO_DEPOT = 0;

    /**
     * Statuts de commande admis : Validée et suivantes (une commande annulée
     * ne se prépare pas).
     *
     * @return list<OrderStatus>
     */
    public static function allowedStatuses(): array
    {
        return OrderStatus::validated();
    }

    /**
     * Statuts de préparation par défaut : le travail restant à faire.
     *
     * @return list<OrderStatus>
     */
    public static function defaultStatuses(): array
    {
        return [OrderStatus::Validated, OrderStatus::InPreparation, OrderStatus::Prepared];
    }

    /**
     * @param  list<OrderStatus>  $statuses
     * @param  list<int>|null  $regionIds  régions lues, null pour toutes (RG-S05)
     */
    public function build(BusinessDay $day, array $statuses, PickingBy $by, ?array $regionIds): PickingReport
    {
        $quantities = $this->quantities($day, $statuses, $regionIds);
        $orderCounts = $this->orderCounts($day, $statuses, $regionIds);

        $regions = Region::query()->with('depot')->whereKey($quantities->pluck('region_id')->unique()->all())->get()->keyBy('id');
        $products = Product::query()->with('family:id,name,sort_order')->whereKey($quantities->pluck('product_id')->unique()->all())->get()->keyBy('id');

        /** @var array<int, list<object>> $byGroup identifiant de groupe → agrégats (région, produit) */
        $byGroup = [];
        foreach ($quantities as $row) {
            $region = $regions[$row->region_id];
            $key = $by === PickingBy::Depot ? ($region->depot_id ?? self::NO_DEPOT) : $region->id;
            $byGroup[$key][] = $row;
        }

        $groups = [];
        foreach ($byGroup as $key => $rows) {
            $groups[] = $this->group($by, $key, $rows, $regions, $products, $orderCounts);
        }
        usort($groups, fn (PickingGroup $a, PickingGroup $b) => [$a->code === null, (string) $a->code] <=> [$b->code === null, (string) $b->code]);

        return new PickingReport(
            $day->day,
            $day->delivery_date,
            $by,
            $statuses,
            BusinessClock::now(),
            $groups,
            $this->totals(array_map(fn (PickingGroup $group) => $group->totals, $groups), $quantities->pluck('product_id')->unique()->count()),
        );
    }

    public function view(PickingReport $report): View
    {
        return view('pdf.picking-list', ['report' => $report]);
    }

    public function pdf(PickingReport $report): string
    {
        return Pdf::loadView('pdf.picking-list', ['report' => $report])
            ->setPaper('a4')
            ->setOption('isFontSubsettingEnabled', true)
            ->addInfo(['Title' => (string) __('documents.picking.title')])
            ->output();
    }

    /**
     * Téléchargement du bon de préparation (PDF ou XLSX), en flux, sans
     * fichier conservé.
     */
    public function response(PickingReport $report, string $format): Response
    {
        $name = 'bon-de-preparation-'.$report->day->format('Y-m-d');

        if ($format === 'xlsx') {
            return Downloads::stream($name.'.xlsx', PickingListSheet::CONTENT_TYPE, fn () => (new PickingListSheet)->write($report));
        }

        return Downloads::content($name.'.pdf', 'application/pdf', $this->pdf($report));
    }

    /**
     * Quantité validée et nombre de commandes par région et produit.
     *
     * @param  list<OrderStatus>  $statuses
     * @param  list<int>|null  $regionIds
     * @return Collection<int, object{region_id: int, product_id: int, quantity: int, orders: int}>
     */
    private function quantities(BusinessDay $day, array $statuses, ?array $regionIds): Collection
    {
        return $this->lines($day, $statuses, $regionIds)
            ->selectRaw('orders.region_id as region_id, order_lines.product_id as product_id, SUM(order_lines.validated_qty) as quantity, COUNT(DISTINCT orders.id) as orders')
            ->groupBy('orders.region_id', 'order_lines.product_id')
            ->get()
            ->map(fn (object $row) => (object) [
                'region_id' => (int) $row->region_id,
                'product_id' => (int) $row->product_id,
                'quantity' => (int) $row->quantity,
                'orders' => (int) $row->orders,
            ]);
    }

    /**
     * Commandes distinctes par région (un produit n'est pas une commande).
     *
     * @param  list<OrderStatus>  $statuses
     * @param  list<int>|null  $regionIds
     * @return array<int, int> région → nombre de commandes
     */
    private function orderCounts(BusinessDay $day, array $statuses, ?array $regionIds): array
    {
        return $this->lines($day, $statuses, $regionIds)
            ->selectRaw('orders.region_id as region_id, COUNT(DISTINCT orders.id) as orders')
            ->groupBy('orders.region_id')
            ->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->region_id => (int) $row->orders])
            ->all();
    }

    /**
     * Lignes validées (> 0) des commandes de la journée aux statuts voulus.
     *
     * @param  list<OrderStatus>  $statuses
     * @param  list<int>|null  $regionIds
     */
    private function lines(BusinessDay $day, array $statuses, ?array $regionIds): Builder
    {
        return DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->where('orders.business_day_id', $day->id)
            ->whereIn('orders.status', OrderStatus::values($statuses))
            ->where('order_lines.validated_qty', '>', 0)
            ->when($regionIds !== null, fn ($query) => $query->whereIn('orders.region_id', $regionIds));
    }

    /**
     * @param  list<object{region_id: int, product_id: int, quantity: int, orders: int}>  $rows
     * @param  Collection<int, Region>  $regions
     * @param  Collection<int, Product>  $products
     * @param  array<int, int>  $orderCounts
     */
    private function group(PickingBy $by, int $key, array $rows, Collection $regions, Collection $products, array $orderCounts): PickingGroup
    {
        $regionIds = array_values(array_unique(array_map(fn (object $row) => $row->region_id, $rows)));
        $groupRegions = $regions->only($regionIds)->sortBy('code')->values();
        if ($by === PickingBy::Region) {
            $region = $groupRegions->first();
            [$label, $code] = [$region->name, $region->code];
        } else {
            $depot = $groupRegions->first()->depot;
            [$label, $code] = $key === self::NO_DEPOT ? [(string) __('documents.picking.no_depot'), null] : [$depot->name, $depot->code];
        }

        /** @var array<int, array{quantity: int, orders: int}> $perProduct */
        $perProduct = [];
        foreach ($rows as $row) {
            $perProduct[$row->product_id]['quantity'] = ($perProduct[$row->product_id]['quantity'] ?? 0) + $row->quantity;
            $perProduct[$row->product_id]['orders'] = ($perProduct[$row->product_id]['orders'] ?? 0) + $row->orders;
        }

        $sorted = $products->only(array_keys($perProduct))->sortBy([
            fn (Product $a, Product $b) => [$a->family->sort_order, $a->family->name, $a->sort_order, $a->sku] <=> [$b->family->sort_order, $b->family->name, $b->sort_order, $b->sku],
        ]);

        $lines = [];
        $pallets = 0.0;
        $withPallets = false;
        foreach ($sorted as $product) {
            $quantity = $perProduct[$product->id]['quantity'];
            $lines[] = new PickingLine(
                $product->family->name,
                $product->sku,
                $product->name,
                $product->sales_unit_label,
                $quantity,
                $perProduct[$product->id]['orders'],
                Pallets::of($quantity, $product->sales_units_per_pallet),
            );
            if (($product->sales_units_per_pallet ?? 0) > 0) {
                $pallets += $quantity / $product->sales_units_per_pallet;
                $withPallets = true;
            }
        }

        return new PickingGroup(
            $label,
            $code,
            $groupRegions->pluck('name')->all(),
            $lines,
            new PickingTotals(
                count($lines),
                array_sum(array_column($perProduct, 'quantity')),
                array_sum(array_map(fn (int $regionId) => $orderCounts[$regionId] ?? 0, $regionIds)),
                $withPallets ? round($pallets, 2) : null,
            ),
        );
    }

    /**
     * Total général : somme des totaux des groupes (une commande appartient
     * à une seule région, donc à un seul groupe) ; `$products` : produits
     * distincts de tout le bon.
     *
     * @param  list<PickingTotals>  $totals
     */
    private function totals(array $totals, int $products): PickingTotals
    {
        $pallets = array_filter(array_map(fn (PickingTotals $total) => $total->pallets, $totals), fn (?float $value) => $value !== null);

        return new PickingTotals(
            $products,
            array_sum(array_map(fn (PickingTotals $total) => $total->quantity, $totals)),
            array_sum(array_map(fn (PickingTotals $total) => $total->orders, $totals)),
            $pallets === [] ? null : round(array_sum($pallets), 2),
        );
    }
}
