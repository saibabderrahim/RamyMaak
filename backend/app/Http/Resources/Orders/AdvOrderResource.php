<?php

namespace App\Http\Resources\Orders;

use App\Models\Order;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Détail d'une commande de la file ADV (`AdvOrder`, contrat du lot 6, §5) :
 * le résumé, le client avec son identité (adresse, NIF, RC…), le
 * commentaire du client, la TVA, le motif de la décision, les lignes avec
 * leurs motifs d'ajustement et d'écart, l'historique avec tous les auteurs
 * nommés et les droits de l'appelant.
 *
 * @mixin Order
 */
class AdvOrderResource extends JsonResource
{
    /** Relations lues par la ressource. */
    public const RELATIONS = [...OrderPresenter::RELATIONS, 'client', 'region.depot', 'decidedBy:id,name'];

    public function __construct(Order $order, private readonly AdvOrderActions $actions)
    {
        parent::__construct($order);
    }

    /** Commande chargée avec ses relations, pour l'appelant. */
    public static function for(Order $order, AdvOrderActions $actions): self
    {
        return new self($order->loadMissing(self::RELATIONS), $actions);
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
            ...AdvOrderSummaryResource::summary($order, $this->actions),
            'client' => [
                'id' => $client->id,
                'code' => $client->code,
                'name' => $client->name,
                'status' => $client->status->value,
                'address' => $client->address,
                'wilaya' => $client->wilaya,
                'commune' => $client->commune,
                'phone' => $client->phone,
                'phone2' => $client->phone2,
                'nif' => $client->nif,
                'rc' => $client->rc,
            ],
            'comment' => $order->client_comment,
            'total_vat' => Money::amount(Money::cents($order->total_vat)),
            'decision_reason' => OrderPresenter::decisionReason($order),
            'decision_comment' => $order->decision_comment,
            'lines' => OrderPresenter::lines($order),
            'history' => OrderPresenter::history($order, true),
            'can' => $this->actions->detail($order),
        ];
    }
}
