<?php

namespace App\Http\Resources\Orders;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Résumé d'une commande vue par le client (`OrderSummary`, contrat du
 * lot 5, §3) : OrderPresenter::summary et droits d'action du client.
 * Journée (`businessDay`) et nombre de lignes (`lines_count`) à charger.
 *
 * @mixin Order
 */
class ClientOrderSummaryResource extends JsonResource
{
    public function __construct(Order $order, private readonly ClientOrderActions $actions)
    {
        parent::__construct($order);
    }

    /**
     * Page de commandes : chaque résumé partage les fenêtres déjà évaluées.
     *
     * @param  LengthAwarePaginator<int, Order>  $page
     */
    public static function page(LengthAwarePaginator $page, ClientOrderActions $actions): AnonymousResourceCollection
    {
        return self::collection($page->through(fn (Order $order) => new self($order, $actions)));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [...OrderPresenter::summary($this->resource), ...$this->actions->rights($this->resource)];
    }
}
