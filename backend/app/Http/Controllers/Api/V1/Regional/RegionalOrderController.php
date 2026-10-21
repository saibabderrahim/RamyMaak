<?php

namespace App\Http\Controllers\Api\V1\Regional;

use App\Domain\Orders\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\ListRegionalOrdersRequest;
use App\Http\Requests\Orders\ShowRegionalOrderRequest;
use App\Http\Resources\Orders\RegionalOrderResource;
use App\Http\Resources\Orders\RegionalOrderSummaryResource;
use App\Models\BusinessDay;
use App\Models\Order;
use App\Support\Pagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Commandes reçues par le régional, en lecture (EF-REG-04 ; contrat du
 * lot 5, §4) : celles des régions de son périmètre à la date de la journée
 * (titulaire ou suppléant), la région étant celle figée à l'envoi. Droits,
 * périmètre et format sont contrôlés par les requêtes (403, puis 404, puis
 * 422). Les décisions (validation, ajustement, rejet) arrivent au lot 6.
 */
class RegionalOrderController extends Controller
{
    /** Taille de page par défaut (contrat §4), 100 au plus (Pagination). */
    private const PER_PAGE = 50;

    /**
     * GET /regional/orders?day=&filter[status]=&filter[region]= : par heure
     * d'envoi croissante (RG-V01), « À valider » par défaut ; `meta.counts`
     * compte les onglets sur la journée et les régions lues, quel que soit
     * le filtre de statut. Nombre de requêtes indépendant du volume.
     */
    public function index(ListRegionalOrdersRequest $request): AnonymousResourceCollection
    {
        $businessDay = BusinessDay::query()->where('day', $request->day()->format('Y-m-d'))->first();
        // Journée non ouverte : aucune commande (business_day_id = 0).
        $scope = fn (): Builder => Order::query()
            ->where('business_day_id', $businessDay?->id ?? 0)
            ->whereIn('region_id', $request->regionIds());

        $orders = $scope()
            ->whereIn('status', OrderStatus::values($request->statuses()))
            ->with(RegionalOrderSummaryResource::RELATIONS)
            ->withCount('lines')
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->paginate($this->perPage($request))
            ->withQueryString();
        // Journée commune à toute la page : posée sans requête.
        $orders->getCollection()->each(fn (Order $order) => $order->setRelation('businessDay', $businessDay));

        return RegionalOrderSummaryResource::collection($orders)->additional([
            'meta' => ['counts' => $this->counts($scope())],
        ]);
    }

    /** GET /regional/orders/{id} : commande, client, région, lignes et leur contexte de quotas, historique. */
    public function show(ShowRegionalOrderRequest $request, string $order): RegionalOrderResource
    {
        return RegionalOrderResource::for($request->order());
    }

    /**
     * Compteurs des onglets, en une requête groupée : « À valider »,
     * « Validées » (Validée à Livrée), « Rejetées », « Annulées et expirées ».
     *
     * @param  Builder<Order>  $scope
     * @return array{SUBMITTED: int, VALIDATED: int, REJECTED: int, CLOSED: int}
     */
    private function counts(Builder $scope): array
    {
        $byStatus = $scope->toBase()
            ->select('status')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $sum = fn (array $statuses): int => array_sum(array_map(fn (OrderStatus $status) => (int) ($byStatus[$status->value] ?? 0), $statuses));

        return [
            'SUBMITTED' => $sum(OrderStatus::pending()),
            'VALIDATED' => $sum(OrderStatus::validated()),
            'REJECTED' => $sum([OrderStatus::Rejected]),
            'CLOSED' => $sum([OrderStatus::Cancelled, OrderStatus::Expired]),
        ];
    }

    private function perPage(Request $request): int
    {
        return $request->query('per_page') === null ? self::PER_PAGE : Pagination::perPage($request);
    }
}
