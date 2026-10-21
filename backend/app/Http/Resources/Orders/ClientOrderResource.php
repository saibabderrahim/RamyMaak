<?php

namespace App\Http\Resources\Orders;

use App\Domain\Orders\OrderStatus;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Commande vue par le client (`Order`, contrat du lot 5, §3 ; lot 6, §4 et
 * §8) : résumé et droits d'action, auteur de l'envoi, commentaire, TVA,
 * décision et son motif avec libellé, heure limite de modification, lignes
 * (motifs d'ajustement avec libellé et commentaire, visibles du client,
 * I-73) et historique. Dans l'historique, seuls les comptes du client sont
 * nommés (OrderPresenter::history).
 *
 * Expédition (I-79) : quantités expédiées et motifs d'écart des lignes (à
 * null avant), `shipped_at` et `delivery_note_ref` (clés absentes avant)
 * n'apparaissent qu'une fois la commande expédiée (Expédiée ou Livrée) ;
 * avant, l'ADV peut encore les modifier et le client ne les voit pas.
 * RG-C09 : jamais de Q, R ni V(r,p) (pas de `context`).
 *
 * @mixin Order
 */
class ClientOrderResource extends JsonResource
{
    /** Relations lues par la ressource. */
    public const RELATIONS = [...OrderPresenter::RELATIONS, 'submittedBy:id,name'];

    public function __construct(Order $order, private readonly ClientOrderActions $actions)
    {
        parent::__construct($order);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;
        $shipped = in_array($order->status, [OrderStatus::Shipped, OrderStatus::Delivered], true);

        return [
            ...OrderPresenter::summary($order),
            ...$this->actions->rights($order),
            'submitted_by' => $order->submittedBy === null ? null : ['id' => $order->submittedBy->id, 'name' => $order->submittedBy->name],
            'comment' => $order->client_comment,
            'total_vat' => Money::amount(Money::cents($order->total_vat)),
            'decided_at' => $order->decided_at?->toIso8601String(),
            'decision_reason_code' => $order->decision_reason_code,
            'decision_reason' => OrderPresenter::decisionReason($order),
            'decision_comment' => $order->decision_comment,
            'modifiable_until' => $this->actions->modifiableUntil($order),
            ...($shipped ? ['shipped_at' => $order->shipped_at?->toIso8601String(), 'delivery_note_ref' => $order->delivery_note_ref] : []),
            'lines' => OrderPresenter::lines($order, $shipped),
            'history' => OrderPresenter::history($order, false),
        ];
    }
}
