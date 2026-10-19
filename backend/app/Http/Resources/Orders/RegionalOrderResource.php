<?php

namespace App\Http\Resources\Orders;

use App\Domain\Orders\OrderUsage;
use App\Models\ClientLimit;
use App\Models\Order;
use App\Models\RegionDay;
use App\Models\RegionQuota;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Commande reçue, vue par le régional (`RegionalOrder`, contrat du lot 5,
 * §4) : la commande du client (sans ses droits d'action ni son heure limite
 * de modification), le client et ses téléphones (appel en un geste), la
 * région, l'historique avec tous les auteurs nommés, et pour chaque ligne
 * le contexte de quotas **publié** du moment (§5.1, EF-REG-05) :
 *
 * - `limit` : borne publiée B(c,p) du client pour la journée régionale de
 *   la commande, null sans borne (les brouillons sont ignorés) ;
 * - `client_validated` : V(c,p), quantités validées du client ce jour-là ;
 * - `region_quota`, `region_validated`, `region_remaining` : Q(r,p),
 *   V(r,p) et R(r,p) = Q − V(r,p).
 *
 * `can_validate` reste faux : les décisions arrivent au lot 6. Nombre de
 * requêtes borné, quel que soit le nombre de lignes.
 *
 * @mixin Order
 */
class RegionalOrderResource extends JsonResource
{
    /** Relations lues par la ressource. */
    public const RELATIONS = [
        ...OrderPresenter::RELATIONS,
        'submittedBy:id,name',
        'client:id,code,name,status,phone,phone2',
        'region:id,code,name',
    ];

    /**
     * @param  array<int, array{limit: ?int, client_validated: int, region_quota: int, region_validated: int, region_remaining: int}>  $context  produit → contexte
     */
    public function __construct(Order $order, private readonly array $context)
    {
        parent::__construct($order);
    }

    /** Commande chargée avec ses relations et le contexte de ses lignes. */
    public static function for(Order $order): self
    {
        $order->loadMissing(self::RELATIONS);

        return new self($order, self::context($order));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;
        $client = $order->client;

        return [
            ...OrderPresenter::summary($order),
            'submitted_by' => $order->submittedBy === null ? null : ['id' => $order->submittedBy->id, 'name' => $order->submittedBy->name],
            'comment' => $order->client_comment,
            'total_vat' => Money::amount(Money::cents($order->total_vat)),
            'decided_at' => $order->decided_at?->toIso8601String(),
            'decision_reason_code' => $order->decision_reason_code,
            'decision_comment' => $order->decision_comment,
            'client' => [...RegionalOrderSummaryResource::client($client), 'phone' => $client->phone, 'phone2' => $client->phone2],
            'region' => RegionalOrderSummaryResource::region($order->region),
            'lines' => array_map(fn (array $line) => [...$line, 'context' => $this->context[$line['product_id']]], OrderPresenter::lines($order)),
            'history' => OrderPresenter::history($order, true),
            'can_validate' => false,
        ];
    }

    /**
     * Contexte publié de chaque ligne, en quatre requêtes : journée
     * régionale de la commande (région figée à l'envoi), bornes du client,
     * quotas de la région, consommation du client (OrderUsage).
     *
     * @return array<int, array{limit: ?int, client_validated: int, region_quota: int, region_validated: int, region_remaining: int}>
     */
    private static function context(Order $order): array
    {
        $productIds = $order->lines->pluck('product_id')->all();
        $regionDayId = RegionDay::query()
            ->where('business_day_id', $order->business_day_id)
            ->where('region_id', $order->region_id)
            ->value('id');
        $limits = $regionDayId === null ? collect() : ClientLimit::query()
            ->where('region_day_id', $regionDayId)
            ->where('client_id', $order->client_id)
            ->whereIn('product_id', $productIds)
            ->pluck('max_qty', 'product_id');
        $quotas = $regionDayId === null ? collect() : RegionQuota::query()
            ->where('region_day_id', $regionDayId)
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'quantity', 'validated_qty'])
            ->keyBy('product_id');
        $usage = app(OrderUsage::class)->forClient($order->business_day_id, $order->client_id);

        $context = [];
        foreach ($productIds as $productId) {
            $limit = $limits->get($productId);
            /** @var RegionQuota|null $quota */
            $quota = $quotas->get($productId);
            $quantity = $quota === null ? 0 : $quota->quantity;
            $validated = $quota === null ? 0 : $quota->validated_qty;
            $context[$productId] = [
                'limit' => $limit === null ? null : (int) $limit,
                'client_validated' => $usage[$productId]['validated'] ?? 0,
                'region_quota' => $quantity,
                'region_validated' => $validated,
                'region_remaining' => $quantity - $validated,
            ];
        }

        return $context;
    }
}
