<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Domain\Orders\ClientOrders;
use App\Domain\Orders\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\ClientOrderCancelRequest;
use App\Http\Requests\Orders\ClientOrderListRequest;
use App\Http\Requests\Orders\ClientOrderShowRequest;
use App\Http\Requests\Orders\ClientOrderSubmitRequest;
use App\Http\Requests\Orders\ClientOrderUpdateRequest;
use App\Http\Resources\Orders\ClientOrderActions;
use App\Http\Resources\Orders\ClientOrderResource;
use App\Http\Resources\Orders\ClientOrderSummaryResource;
use App\Models\BusinessDay;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use App\Support\BusinessClock;
use App\Support\FilterValue;
use App\Support\Pagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Commandes du client (EF-CLT-03, EF-CLT-04 ; contrat du lot 5, §3) :
 * celles du client, tous ses comptes confondus (Q-11). Droits, format de
 * l'identifiant et appartenance de la commande sont contrôlés par les
 * requêtes (403, puis 404, puis 422) ; tous les contrôles métier par
 * ClientOrders, dont les refus parviennent tels quels (Problem Details et
 * leurs membres : violations, max_orders_per_day, version…).
 */
class ClientOrderController extends Controller
{
    public function __construct(private readonly ClientOrders $orders) {}

    /**
     * GET /client/orders : toutes journées, plus récentes d'abord ;
     * `filter[day]` (une journée), `filter[status]` (liste séparée par des
     * virgules) ; 25 par page, 100 au plus.
     */
    public function index(ClientOrderListRequest $request): AnonymousResourceCollection
    {
        $client = $request->client();

        $orders = QueryBuilder::for(Order::query()->where('client_id', $client->id)->with('businessDay')->withCount('lines'))
            ->allowedFilters(
                AllowedFilter::callback('day', function (Builder $query, mixed $value): void {
                    $values = FilterValue::scalars($value, 'day');
                    $day = count($values) === 1 ? BusinessClock::parseDay(trim($values[0])) : null;
                    if ($day === null) {
                        throw ValidationException::withMessages(['filter.day' => [__('orders.client.day_format')]]);
                    }

                    $query->whereIn('business_day_id', BusinessDay::query()->select('id')->where('day', $day->format('Y-m-d')));
                }),
                AllowedFilter::callback('status', function (Builder $query, mixed $value): void {
                    $statuses = collect(FilterValue::scalars($value, 'status'))->map(fn (string $s) => OrderStatus::tryFrom(mb_strtoupper(trim($s))));
                    if ($statuses->contains(null)) {
                        throw ValidationException::withMessages(['filter.status' => [__('orders.client.unknown_status')]]);
                    }

                    $query->whereIn('status', $statuses->map->value->unique()->values()->all());
                }),
            )
            // Ordre fixe (plus récentes d'abord) : tout tri demandé → 422.
            ->allowedSorts()
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        $actions = ClientOrderActions::for($client);
        $orders->getCollection()->each(fn (Order $order) => $actions->rememberDay($order->businessDay));

        return ClientOrderSummaryResource::page($orders, $actions);
    }

    /** GET /client/orders/{id} */
    public function show(ClientOrderShowRequest $request, string $order): ClientOrderResource
    {
        return $this->present($request->order(), $request->client());
    }

    /**
     * POST /client/orders : 201 pour une nouvelle commande, 200 pour le
     * renvoi d'un même `client_request_id` (RG-C07, I-54).
     */
    public function store(ClientOrderSubmitRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        ['order' => $order, 'created' => $created] = $this->orders->submit(
            $user,
            $request->requestId(),
            $request->day(),
            $request->lines(),
            $request->comment(),
        );

        return $this->present($order->refresh(), $request->client())
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    /** PATCH /client/orders/{id} : remplacement complet des lignes et du commentaire (RG-C05, I-51). */
    public function update(ClientOrderUpdateRequest $request, string $order): ClientOrderResource
    {
        /** @var User $user */
        $user = $request->user();

        $modified = $this->orders->modify($user, $request->order()->public_id, $request->version(), $request->lines(), $request->comment());

        return $this->present($modified, $request->client());
    }

    /** POST /client/orders/{id}/cancel (I-52). */
    public function cancel(ClientOrderCancelRequest $request, string $order): ClientOrderResource
    {
        /** @var User $user */
        $user = $request->user();

        $cancelled = $this->orders->cancel($user, $request->order()->public_id, $request->version());

        return $this->present($cancelled, $request->client());
    }

    private function present(Order $order, Client $client): ClientOrderResource
    {
        return new ClientOrderResource($order->load(ClientOrderResource::RELATIONS), ClientOrderActions::for($client));
    }
}
