<?php

namespace App\Http\Resources\Orders;

use App\Models\Depot;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Region;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Commande de la file ADV, dans la liste (`AdvOrderSummary`, contrat du
 * lot 6, §5) : OrderPresenter::summary, validation (heure, auteur),
 * horodatages de traitement, bon de livraison, client, région figée et son
 * dépôt actuel, écart d'expédition, et droits de l'appelant.
 *
 * À charger : RELATIONS, `lines_count` et, pour une liste, `has_shipping_gap`
 * (withExists) ; sinon l'écart est lu sur les lignes chargées.
 *
 * @mixin Order
 */
class AdvOrderSummaryResource extends JsonResource
{
    /** Relations lues par la ressource. */
    public const RELATIONS = ['businessDay:id,day', 'client:id,code,name,status', 'region:id,code,name,depot_id', 'region.depot:id,code,name', 'decidedBy:id,name'];

    public function __construct(Order $order, private readonly AdvOrderActions $actions)
    {
        parent::__construct($order);
    }

    /**
     * Page de commandes : les droits de l'appelant sont évalués une fois.
     *
     * @param  LengthAwarePaginator<int, Order>  $page
     */
    public static function page(LengthAwarePaginator $page, AdvOrderActions $actions): AnonymousResourceCollection
    {
        return self::collection($page->through(fn (Order $order) => new self($order, $actions)));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return self::summary($this->resource, $this->actions);
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(Order $order, AdvOrderActions $actions): array
    {
        $summary = OrderPresenter::summary($order);
        $client = $order->client;

        return [
            ...array_intersect_key($summary, array_flip(['id', 'number', 'day', 'status', 'status_label', 'version', 'submitted_at'])),
            'decided_at' => $order->decided_at?->toIso8601String(),
            'decided_by' => $order->decidedBy === null ? null : ['id' => $order->decidedBy->id, 'name' => $order->decidedBy->name],
            'prep_started_at' => $order->prep_started_at?->toIso8601String(),
            'prepared_at' => $order->prepared_at?->toIso8601String(),
            'shipped_at' => $order->shipped_at?->toIso8601String(),
            'delivery_note_ref' => $order->delivery_note_ref,
            'client' => ['id' => $client->id, 'code' => $client->code, 'name' => $client->name, 'status' => $client->status->value],
            'region' => self::region($order->region),
            'depot' => self::depot($order->region->depot),
            'lines_count' => $summary['lines_count'],
            'total_ht' => $summary['total_ht'],
            'total_ttc' => $summary['total_ttc'],
            'is_adjusted' => $summary['is_adjusted'],
            'has_shipping_gap' => self::hasShippingGap($order),
            'next_statuses' => $actions->nextStatuses($order),
            'can_cancel' => $actions->canCancel($order),
        ];
    }

    /**
     * @return array{id: int, code: string, name: string}
     */
    public static function region(Region $region): array
    {
        return ['id' => $region->id, 'code' => $region->code, 'name' => $region->name];
    }

    /**
     * @return array{id: int, code: string, name: string}|null
     */
    public static function depot(?Depot $depot): ?array
    {
        return $depot === null ? null : ['id' => $depot->id, 'code' => $depot->code, 'name' => $depot->name];
    }

    /** Une ligne expédiée en dessous de sa quantité validée (RG-A03). */
    private static function hasShippingGap(Order $order): bool
    {
        if (array_key_exists('has_shipping_gap', $order->getAttributes())) {
            return (bool) $order->getAttribute('has_shipping_gap');
        }

        return $order->lines->contains(fn (OrderLine $line) => $line->shipped_qty !== null && $line->shipped_qty < (int) $line->validated_qty);
    }
}
