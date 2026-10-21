<?php

namespace App\Http\Resources\Orders;

use App\Domain\Orders\DecisionRights;
use App\Models\Order;
use App\Models\RegionDay;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Commande reçue, vue par le régional (`RegionalOrder`, contrats des lots 5
 * et 6, §4 et §3) : la commande du client (sans ses droits d'action ni son
 * heure limite de modification), le client et ses téléphones (appel en un
 * geste), la région, l'historique avec tous les auteurs nommés, la décision
 * (date, auteur, motif avec son libellé), la fenêtre de validation et les
 * droits de décision du lecteur (`can_validate`, `can_reject`,
 * `blocked_reason` : DecisionRights), et pour chaque ligne le motif
 * d'ajustement, son commentaire et le contexte de quotas **publié** du
 * moment (§5.1, EF-REG-05) :
 *
 * - `limit` : borne publiée B(c,p) du client pour la journée régionale de
 *   la commande, null sans borne (les brouillons sont ignorés) ;
 * - `client_validated` : V(c,p), quantités validées du client ce jour-là ;
 * - `region_quota`, `region_validated`, `region_remaining` : Q(r,p),
 *   V(r,p) et R(r,p) = Q − V(r,p) ;
 * - `max_validatable` : max(0, min(demandé, R, B − V(c,p))), la formule de
 *   la validation (« Ajuster au maximum possible »).
 *
 * Nombre de requêtes borné, quel que soit le nombre de lignes.
 *
 * @mixin Order
 */
class RegionalOrderResource extends JsonResource
{
    /**
     * Relations lues par la ressource : la journée en entier (ses heures
     * limites servent à la fenêtre de validation), les autres comme
     * OrderPresenter::RELATIONS.
     */
    public const RELATIONS = [
        'businessDay',
        'lines.product:id,sku,name,short_name,sales_unit_label',
        'lines.product.media',
        'history.user:id,name',
        'submittedBy:id,name',
        'decidedBy:id,name',
        'client:id,code,name,status,phone,phone2',
        'region:id,code,name',
    ];

    /**
     * @param  array<int, array{limit: ?int, client_validated: int, region_quota: int, region_validated: int, region_remaining: int, max_validatable: int}>  $context  produit → contexte
     */
    public function __construct(Order $order, private readonly array $context, private readonly ?RegionDay $regionDay = null)
    {
        parent::__construct($order);
    }

    /** Commande chargée avec ses relations et le contexte de ses lignes. */
    public static function for(Order $order): self
    {
        $order->loadMissing(self::RELATIONS);
        $rights = app(DecisionRights::class);
        $regionDay = $rights->regionDayOf($order);

        return new self($order, $rights->context($order, $regionDay), $regionDay);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;
        /** @var User $user */
        $user = $request->user();
        $client = $order->client;

        return [
            ...OrderPresenter::summary($order),
            'submitted_by' => $order->submittedBy === null ? null : ['id' => $order->submittedBy->id, 'name' => $order->submittedBy->name],
            'comment' => $order->client_comment,
            'total_vat' => Money::amount(Money::cents($order->total_vat)),
            'decided_at' => $order->decided_at?->toIso8601String(),
            'decided_by' => RegionalOrderSummaryResource::decider($order),
            'decision_reason' => OrderPresenter::decisionReason($order),
            'decision_reason_code' => $order->decision_reason_code,
            'decision_comment' => $order->decision_comment,
            ...app(DecisionRights::class)->evaluate($user, $order, $this->regionDay)->toArray(),
            'client' => [...RegionalOrderSummaryResource::client($client), 'phone' => $client->phone, 'phone2' => $client->phone2],
            'region' => RegionalOrderSummaryResource::region($order->region),
            'lines' => array_map(fn (array $line) => [...$line, 'context' => $this->context[$line['product_id']]], OrderPresenter::lines($order)),
            'history' => OrderPresenter::history($order, true),
        ];
    }
}
