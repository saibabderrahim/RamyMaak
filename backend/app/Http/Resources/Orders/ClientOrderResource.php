<?php

namespace App\Http\Resources\Orders;

use App\Models\Order;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Commande vue par le client (`Order`, contrat du lot 5, §3) : résumé et
 * droits d'action, auteur de l'envoi, commentaire, TVA, décision (lot 6),
 * heure limite de modification, lignes et historique. Dans l'historique,
 * seuls les comptes du client sont nommés (OrderPresenter::history).
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

        return [
            ...OrderPresenter::summary($order),
            ...$this->actions->rights($order),
            'submitted_by' => $order->submittedBy === null ? null : ['id' => $order->submittedBy->id, 'name' => $order->submittedBy->name],
            'comment' => $order->client_comment,
            'total_vat' => Money::amount(Money::cents($order->total_vat)),
            'decided_at' => $order->decided_at?->toIso8601String(),
            'decision_reason_code' => $order->decision_reason_code,
            'decision_comment' => $order->decision_comment,
            'modifiable_until' => $this->actions->modifiableUntil($order),
            'lines' => OrderPresenter::lines($order),
            'history' => OrderPresenter::history($order, false),
        ];
    }
}
