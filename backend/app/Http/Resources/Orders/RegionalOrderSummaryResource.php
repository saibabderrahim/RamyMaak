<?php

namespace App\Http\Resources\Orders;

use App\Models\Client;
use App\Models\Order;
use App\Models\Region;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * Commande reçue, dans la liste du régional (`RegionalOrderSummary`,
 * contrat du lot 5, §4) : OrderPresenter::summary sans le TTC, date de
 * dernière écriture, client et région (figée à l'envoi). Aucun droit
 * d'action au lot 5. À charger : RELATIONS, `businessDay` et `lines_count`.
 *
 * @mixin Order
 */
class RegionalOrderSummaryResource extends JsonResource
{
    /** Relations lues par la ressource (la journée, commune à la liste, est posée par le contrôleur). */
    public const RELATIONS = ['client:id,code,name,status', 'region:id,code,name'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;
        $summary = OrderPresenter::summary($order);

        return [
            ...Arr::only($summary, ['id', 'number', 'day', 'status', 'status_label', 'version', 'submitted_at']),
            'updated_at' => $order->updated_at?->toIso8601String(),
            'client' => self::client($order->client),
            'region' => self::region($order->region),
            'lines_count' => $summary['lines_count'],
            'total_ht' => $summary['total_ht'],
            'is_adjusted' => $summary['is_adjusted'],
        ];
    }

    /**
     * @return array{id: int, code: string, name: string, status: string}
     */
    public static function client(Client $client): array
    {
        return ['id' => $client->id, 'code' => $client->code, 'name' => $client->name, 'status' => $client->status->value];
    }

    /**
     * @return array{id: int, code: string, name: string}
     */
    public static function region(Region $region): array
    {
        return ['id' => $region->id, 'code' => $region->code, 'name' => $region->name];
    }
}
