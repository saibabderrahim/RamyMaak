<?php

namespace App\Domain\Documents;

use App\Domain\Orders\OrderStatus;
use App\Domain\Reasons\ReasonCodes;
use App\Domain\Reasons\ReasonType;
use App\Models\BusinessDay;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Export des commandes de la journée (RG-A04, EF-ADV-07, I-83, I-85) : une
 * ligne par ligne de commande, en XLSX, CSV pour Excel ou format ERP
 * provisoire. Les lignes sont lues par lots de commandes (identifiant
 * croissant) : la mémoire ne dépend pas du volume, et le nombre de requêtes
 * est de deux par lot de `CHUNK` commandes, plus un comptage préalable qui
 * refuse l'export au-delà de `MAX_ROWS` lignes (422 sur `filter`) avant
 * d'envoyer le moindre octet.
 */
final class OrderExport
{
    /** Lignes au plus par export (contrat du lot 6, §6). */
    public const MAX_ROWS = 100_000;

    /** Commandes lues à la fois : 30 lignes au plus chacune, soit quelques milliers de lignes en mémoire. */
    private const CHUNK = 100;

    public function __construct(
        private readonly ReasonCodes $reasons,
        private readonly int $maxRows = self::MAX_ROWS,
    ) {}

    /**
     * Téléchargement de l'export, écrit en flux.
     *
     * @param  list<OrderStatus>  $statuses
     * @param  list<int>|null  $regionIds  régions lues, null pour toutes (RG-S05)
     *
     * @throws ValidationException `filter` au-delà de la limite de lignes
     */
    public function response(BusinessDay $day, ExportFormat $format, array $statuses, ?array $regionIds): Response
    {
        $rows = $this->count($day, $statuses, $regionIds);
        if ($rows > $this->maxRows) {
            throw ValidationException::withMessages(['filter' => [__('documents.export.too_many_rows', ['max' => Formats::integer($this->maxRows)])]]);
        }

        $layout = $format->layout();
        $this->reasons->preload();

        return Downloads::stream(
            $layout->filename($day),
            $layout->contentType(),
            fn () => $layout->write($this->lines($day, $statuses, $regionIds)),
        );
    }

    /**
     * Nombre de lignes de l'export (une requête).
     *
     * @param  list<OrderStatus>  $statuses
     * @param  list<int>|null  $regionIds
     */
    public function count(BusinessDay $day, array $statuses, ?array $regionIds): int
    {
        return $this->scope(DB::table('order_lines')->join('orders', 'orders.id', '=', 'order_lines.order_id'), $day, $statuses, $regionIds)->count();
    }

    /**
     * Lignes de l'export, par commande (identifiant croissant) puis par ligne.
     *
     * @param  list<OrderStatus>  $statuses
     * @param  list<int>|null  $regionIds
     * @return Generator<int, ExportLine>
     */
    public function lines(BusinessDay $day, array $statuses, ?array $regionIds): Generator
    {
        $after = 0;
        while (true) {
            $orders = $this->scope(
                DB::table('orders')
                    ->join('clients', 'clients.id', '=', 'orders.client_id')
                    ->join('regions', 'regions.id', '=', 'orders.region_id')
                    ->leftJoin('depots', 'depots.id', '=', 'regions.depot_id')
                    ->leftJoin('users as deciders', 'deciders.id', '=', 'orders.decided_by'),
                $day,
                $statuses,
                $regionIds,
            )
                ->where('orders.id', '>', $after)
                ->orderBy('orders.id')
                ->limit(self::CHUNK)
                ->get([
                    'orders.id', 'orders.number', 'orders.status', 'orders.is_adjusted', 'orders.submitted_at', 'orders.decided_at',
                    'orders.shipped_at', 'orders.delivery_note_ref', 'clients.code as client_code', 'clients.name as client_name',
                    'regions.code as region_code', 'regions.name as region_name', 'depots.code as depot_code', 'depots.name as depot_name',
                    'deciders.name as decided_by',
                ]);
            if ($orders->isEmpty()) {
                return;
            }

            $lines = DB::table('order_lines')
                ->join('products', 'products.id', '=', 'order_lines.product_id')
                ->whereIn('order_lines.order_id', $orders->pluck('id')->all())
                ->orderBy('order_lines.order_id')
                ->orderBy('order_lines.id')
                ->get([
                    'order_lines.order_id', 'order_lines.requested_qty', 'order_lines.validated_qty', 'order_lines.shipped_qty',
                    'order_lines.unit_price_ht', 'order_lines.vat_rate', 'order_lines.adjustment_reason_code',
                    'order_lines.shipping_gap_reason_code', 'products.sku', 'products.name', 'products.sales_unit_label',
                ])
                ->groupBy('order_id');

            foreach ($orders as $row) {
                $order = $this->order($day, $row);
                foreach ($lines->get($row->id, []) as $line) {
                    yield $this->line($order, $line);
                }
            }
            if ($orders->count() < self::CHUNK) {
                return;
            }
            $after = (int) $orders->last()->id;
        }
    }

    /**
     * Commandes de la journée aux statuts voulus, dans les régions lues.
     *
     * @param  list<OrderStatus>  $statuses
     * @param  list<int>|null  $regionIds
     */
    private function scope(Builder $query, BusinessDay $day, array $statuses, ?array $regionIds): Builder
    {
        return $query
            ->where('orders.business_day_id', $day->id)
            ->whereIn('orders.status', OrderStatus::values($statuses))
            ->when($regionIds !== null, fn (Builder $query) => $query->whereIn('orders.region_id', $regionIds));
    }

    private function order(BusinessDay $day, object $row): ExportOrder
    {
        return new ExportOrder(
            $day->day,
            $day->delivery_date,
            (string) $row->number,
            OrderStatus::from($row->status),
            (bool) $row->is_adjusted,
            $row->client_code,
            $row->client_name,
            $row->region_code,
            $row->region_name,
            $row->depot_code,
            $row->depot_name,
            self::instant($row->submitted_at),
            self::instant($row->decided_at),
            $row->decided_by,
            self::instant($row->shipped_at),
            $row->delivery_note_ref,
        );
    }

    private function line(ExportOrder $order, object $row): ExportLine
    {
        $price = Money::cents($row->unit_price_ht);
        $validated = $row->validated_qty === null ? null : (int) $row->validated_qty;

        return new ExportLine(
            $order,
            $row->sku,
            $row->name,
            $row->sales_unit_label,
            (int) $row->requested_qty,
            $validated,
            $row->shipped_qty === null ? null : (int) $row->shipped_qty,
            $this->reasons->label(ReasonType::Adjustment, $row->adjustment_reason_code),
            $this->reasons->label(ReasonType::ShippingGap, $row->shipping_gap_reason_code),
            Money::amount($price),
            (float) $row->vat_rate,
            $validated === null ? null : Money::amount($price * $validated),
        );
    }

    /** Instant lu en base (UTC, sans fuseau dans le texte). */
    private static function instant(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC');
    }
}
