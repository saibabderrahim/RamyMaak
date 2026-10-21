<?php

namespace App\Http\Controllers\Api\V1\Adv;

use App\Domain\Access\Perimeter;
use App\Domain\Documents\OrderPdf;
use App\Domain\Orders\AdvOrders;
use App\Domain\Orders\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\AdvOrderBulkStatusRequest;
use App\Http\Requests\Orders\AdvOrderCancelRequest;
use App\Http\Requests\Orders\AdvOrderListRequest;
use App\Http\Requests\Orders\AdvOrderPdfRequest;
use App\Http\Requests\Orders\AdvOrderShippingRequest;
use App\Http\Requests\Orders\AdvOrderShowRequest;
use App\Http\Resources\Orders\AdvOrderActions;
use App\Http\Resources\Orders\AdvOrderResource;
use App\Http\Resources\Orders\AdvOrderSummaryResource;
use App\Models\BusinessDay;
use App\Models\Client;
use App\Models\Order;
use App\Models\Region;
use App\Models\User;
use App\Support\Pagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * File des commandes validées de l'ADV (EF-ADV-04 ; contrat du lot 6, §5) :
 * liste, détail, statuts en masse, expédition, annulation exceptionnelle et
 * bon de commande. Droits, périmètre (RG-S05, à la journée de chaque
 * commande) et forme sont contrôlés par les requêtes (403, puis 404, puis
 * 422) ; toutes les règles métier par AdvOrders, dont les refus parviennent
 * tels quels (Problem Details et leurs membres : version…). L'Admin lit la
 * file et le détail, sans rien écrire (I-87).
 */
class AdvOrderController extends Controller
{
    /** Taille de page par défaut (contrat §5), 100 au plus (Pagination). */
    private const PER_PAGE = 50;

    /** Onglets de la file et leurs compteurs (hors Livrée, V2). */
    private const TABS = [OrderStatus::Validated, OrderStatus::InPreparation, OrderStatus::Prepared, OrderStatus::Shipped, OrderStatus::Cancelled];

    public function __construct(private readonly AdvOrders $orders) {}

    /**
     * GET /adv/orders : par heure de validation croissante (premier validé,
     * premier préparé), travail en cours sur toutes les journées par défaut.
     * `meta.counts` compte les onglets avec les mêmes filtres, sauf le statut.
     * Nombre de requêtes indépendant du volume.
     */
    public function index(AdvOrderListRequest $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $scope = fn (): Builder => $this->scope($request, $user);

        $orders = $this->sorted($scope()->whereIn('orders.status', OrderStatus::values($request->statuses())), $request->sort())
            ->with(AdvOrderSummaryResource::RELATIONS)
            ->withCount('lines')
            ->withExists(['lines as has_shipping_gap' => fn (Builder $lines) => $lines->whereColumn('order_lines.shipped_qty', '<', 'order_lines.validated_qty')])
            ->paginate($this->perPage($request))
            ->withQueryString();

        return AdvOrderSummaryResource::page($orders, AdvOrderActions::for($user))->additional([
            'meta' => ['counts' => $this->counts($scope())],
        ]);
    }

    /** GET /adv/orders/{id} */
    public function show(AdvOrderShowRequest $request, string $order): AdvOrderResource
    {
        /** @var User $user */
        $user = $request->user();

        return $this->present($request->order(), $user);
    }

    /**
     * POST /adv/orders/bulk-status : une étape à la fois, résultat partiel
     * (200 avec `applied` et `refused`), un refus n'annule pas les autres (I-78).
     */
    public function bulkStatus(AdvOrderBulkStatusRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->orders->transition($user, $request->status(), $request->items())->toArray());
    }

    /** PATCH /adv/orders/{id}/shipping : quantités expédiées, motifs d'écart, bon de livraison (RG-A03). */
    public function shipping(AdvOrderShippingRequest $request, string $order): AdvOrderResource
    {
        /** @var User $user */
        $user = $request->user();

        $updated = $this->orders->updateShipping(
            $user,
            $request->order(),
            $request->version(),
            $request->lines(),
            $request->deliveryNoteRef(),
            $request->deliveryNoteGiven(),
        );

        return $this->present($updated, $user);
    }

    /** POST /adv/orders/{id}/cancel : annulation exceptionnelle, V restitué même après la clôture (I-76). */
    public function cancel(AdvOrderCancelRequest $request, string $order): AdvOrderResource
    {
        /** @var User $user */
        $user = $request->user();

        $cancelled = $this->orders->cancel($user, $request->order(), $request->version(), $request->reasonCode(), $request->comment());

        return $this->present($cancelled, $user);
    }

    /**
     * GET /adv/orders/{id}/pdf : droits et périmètre ici, contenu du bon de
     * commande par Documents\OrderPdf (RG-A04, I-86).
     */
    public function pdf(AdvOrderPdfRequest $request, string $order): Response
    {
        return app(OrderPdf::class)->response($request->order());
    }

    private function present(Order $order, User $user): AdvOrderResource
    {
        return AdvOrderResource::for($order, AdvOrderActions::for($user));
    }

    /**
     * Commandes de la file dans le périmètre de l'appelant, avec tous les
     * filtres sauf le statut (base de la liste et des compteurs).
     *
     * @return Builder<Order>
     */
    private function scope(AdvOrderListRequest $request, User $user): Builder
    {
        $query = AdvOrders::visible(app(Perimeter::class)->constrainOrders(Order::query(), $user));

        if (($day = $request->day()) !== null) {
            $query->whereIn('orders.business_day_id', BusinessDay::query()->select('id')->where('day', $day->format('Y-m-d')));
        }
        if (($region = $request->regionId()) !== null) {
            $query->where('orders.region_id', $region);
        }
        $depot = $request->depot();
        if ($depot !== null) {
            $query->whereIn('orders.region_id', Region::query()->select('id')->when(
                $depot === 'none',
                fn (Builder $regions) => $regions->whereNull('depot_id'),
                fn (Builder $regions) => $regions->where('depot_id', $depot),
            ));
        }
        if (($client = $request->clientId()) !== null) {
            $query->where('orders.client_id', $client);
        }
        if (($product = $request->productId()) !== null) {
            $query->whereExists(fn ($lines) => $lines
                ->selectRaw('1')
                ->from('order_lines')
                ->whereColumn('order_lines.order_id', 'orders.id')
                ->where('order_lines.product_id', $product));
        }
        if (($search = $request->search()) !== null) {
            // Échappement explicite (« ! ») : les codes clients contiennent des « _ ».
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(fn (Builder $match) => $match
                ->whereRaw("orders.number LIKE ? ESCAPE '!'", [$term])
                ->orWhereIn('orders.client_id', Client::query()->select('id')->where(fn (Builder $clients) => $clients
                    ->whereRaw("code LIKE ? ESCAPE '!'", [$term])
                    ->orWhereRaw("name LIKE ? ESCAPE '!'", [$term]))));
        }

        return $query;
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private function sorted(Builder $query, string $sort): Builder
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        return $query->orderBy('orders.'.ltrim($sort, '-'), $direction)->orderBy('orders.id', $direction);
    }

    /**
     * Compteurs des onglets, en une requête groupée.
     *
     * @param  Builder<Order>  $scope
     * @return array<string, int>
     */
    private function counts(Builder $scope): array
    {
        $byStatus = $scope->toBase()
            ->select('orders.status')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('orders.status')
            ->pluck('aggregate', 'status');

        $counts = [];
        foreach (self::TABS as $status) {
            $counts[$status->value] = (int) ($byStatus[$status->value] ?? 0);
        }

        return $counts;
    }

    private function perPage(Request $request): int
    {
        return $request->query('per_page') === null ? self::PER_PAGE : Pagination::perPage($request);
    }
}
