<?php

namespace App\Domain\Orders;

use App\Domain\Referentials\ClientStatus;
use App\Domain\Settings\Settings;
use App\Events\OrderCancelled;
use App\Events\OrderSubmitted;
use App\Events\OrderUpdated;
use App\Http\Problems\ApiException;
use App\Http\Problems\ErrorCode;
use App\Models\BusinessDay;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\RegionDay;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Commandes du client (§5.4, §5.8, §6.3) : envoi idempotent, modification
 * et annulation tant que la commande est envoyée et l'heure limite non
 * atteinte. Chaque écriture se fait en une transaction qui commence par
 * verrouiller le client (FOR UPDATE) : les envois d'un même client depuis
 * plusieurs appareils sont sérialisés et E(c,p) est relu après le verrou.
 *
 * Ordre des verrous, le même partout (OrderLocks, I-61, I-67) : client →
 * journée (partagé) → commande → numéro du jour (pris en dernier, juste
 * avant le commit). La clôture verrouille la journée puis les commandes,
 * jamais le client : pas de cycle. Tout écrivain d'une commande envoyée
 * (lot 6 compris) prend la journée en partagé avant la commande.
 */
final class ClientOrders
{
    /** Tentatives d'une transaction interrompue par un interblocage (MySQL). */
    private const ATTEMPTS = 3;

    public function __construct(
        private readonly OrderingWindow $window,
        private readonly OrderBook $book,
        private readonly OrderNumbers $numbers,
        private readonly OrderWorkflow $workflow,
        private readonly Settings $settings,
        private readonly OrderLocks $locks,
        private readonly OrderVersion $versions,
        private readonly OrderHistory $history,
    ) {}

    /**
     * Envoi (RG-C01 à RG-C07). Un renvoi du même `client_request_id` rend la
     * commande existante, dans son état actuel, sans nouveau contrôle.
     *
     * @param  list<array{product_id: int, quantity: int}>  $lines
     * @return array{order: Order, created: bool}
     *
     * @throws ApiException CLIENT_BLOCKED, DAY_NOT_OPEN, CUTOFF_PASSED, MAX_ORDERS_REACHED,
     *                      PRODUCT_NOT_AVAILABLE, CLIENT_LIMIT_EXCEEDED, ORDER_BELOW_MINIMUM
     */
    public function submit(User $by, string $requestId, CarbonImmutable $day, array $lines, ?string $comment): array
    {
        $requestId = strtolower($requestId);

        try {
            return DB::transaction(function () use ($by, $requestId, $day, $lines, $comment): array {
                $client = $this->lockClient($by);

                $existing = Order::query()->where('client_id', $client->id)->where('client_request_id', $requestId)->first();
                if ($existing !== null) {
                    return ['order' => $existing, 'created' => false];
                }

                $this->assertActive($client);
                $state = $this->window->state($client, $day, lock: true);
                $this->window->assertOpen($state);
                /** @var BusinessDay $businessDay */
                $businessDay = $state->businessDay;
                /** @var RegionDay $regionDay */
                $regionDay = $state->regionDay;

                $max = (int) $this->settings->get('orders.max_per_day');
                $count = Order::query()
                    ->where('client_id', $client->id)
                    ->where('business_day_id', $businessDay->id)
                    ->whereIn('status', OrderStatus::values(OrderStatus::counted()))
                    ->count();
                if ($count >= $max) {
                    throw new ApiException(ErrorCode::MaxOrdersReached, trans_choice('orders.max_orders', $max, ['count' => $max]), [
                        'max_orders_per_day' => $max,
                    ]);
                }

                $priced = $this->checkLines($regionDay, $client, $lines);
                $totals = $this->totals($priced);
                $this->assertMinimum($totals['ht']);

                $order = Order::create([
                    'business_day_id' => $businessDay->id,
                    'region_id' => $regionDay->region_id,
                    'client_id' => $client->id,
                    'status' => OrderStatus::Submitted,
                    'version' => 1,
                    'client_request_id' => $requestId,
                    'submitted_at' => now(),
                    'submitted_by' => $by->id,
                    'client_comment' => $this->comment($comment),
                    ...OrderTotals::columns($totals),
                ]);
                $this->insertLines($order, $priced);
                $this->history->record($order, null, OrderStatus::Submitted, OrderActor::Client, $by->id);
                // Numéro en dernier : la ligne du jour, commune à tous les envois, est tenue le moins longtemps possible.
                $order->forceFill(['number' => $this->numbers->next($businessDay)])->save();

                activity('orders')
                    ->performedOn($order)
                    ->causedBy($by)
                    ->event('submitted')
                    ->withProperties([
                        'number' => $order->number,
                        'day' => $businessDay->dayKey(),
                        'client' => $client->code,
                        'lines' => $this->auditLines($priced),
                        'total_ht' => Money::amount($totals['ht']),
                    ])
                    ->log(__('orders.audit.submitted'));

                event(new OrderSubmitted($order->id, $client->id, $order->region_id, $businessDay->id, 1, $by->id));

                return ['order' => $order, 'created' => true];
            }, self::ATTEMPTS);
        } catch (UniqueConstraintViolationException $e) {
            // Filet : deux envois simultanés du même identifiant (RG-C07).
            $existing = Order::query()->where('client_id', $by->client_id)->where('client_request_id', $requestId)->first();
            if ($existing === null) {
                throw $e;
            }

            return ['order' => $existing, 'created' => false];
        }
    }

    /**
     * Modification d'une commande envoyée (RG-C05) : remplacement complet
     * des lignes et du commentaire, tous les contrôles rejoués, E(c,p) hors
     * de cette commande. Les produits gardés gardent leur prix figé ; les
     * nouveaux prennent le prix du jour (I-47). Rien de changé : la commande
     * est rendue telle quelle, sans nouvelle version ni notification.
     *
     * @param  list<array{product_id: int, quantity: int}>  $lines
     *
     * @throws ApiException NOT_FOUND, INVALID_TRANSITION, VERSION_CONFLICT, CLIENT_BLOCKED, DAY_NOT_OPEN,
     *                      CUTOFF_PASSED, PRODUCT_NOT_AVAILABLE, CLIENT_LIMIT_EXCEEDED, ORDER_BELOW_MINIMUM
     */
    public function modify(User $by, string $publicId, int $version, array $lines, ?string $comment): Order
    {
        ['id' => $orderId, 'day' => $dayId] = $this->orderOf($by, $publicId);

        return DB::transaction(function () use ($by, $orderId, $dayId, $version, $lines, $comment): Order {
            $client = $this->lockClient($by);
            $businessDay = $this->locks->day($dayId);
            $order = $this->lockOrder($orderId);
            $this->workflow->assertCan($order->status, OrderStatus::Submitted, OrderActor::Client);
            $this->versions->assert($order, $version);
            $this->assertActive($client);
            $state = $this->window->forRegion($businessDay, $order->region_id);
            $this->window->assertOpen($state);
            /** @var RegionDay $regionDay */
            $regionDay = $state->regionDay;

            $current = $order->lines()->get()->keyBy('product_id');
            $priced = $this->checkLines($regionDay, $client, $lines, $order->id, $current);
            $totals = $this->totals($priced);
            $this->assertMinimum($totals['ht']);

            $comment = $this->comment($comment);
            $before = $current->map(fn (OrderLine $line) => $line->requested_qty)->all();
            $after = collect($priced)->mapWithKeys(fn (array $line) => [$line['product_id'] => $line['quantity']])->all();
            ksort($before);
            ksort($after);
            if ($before === $after && $comment === $order->client_comment) {
                return $order;
            }

            $this->versions->bump($order, $version, OrderStatus::Submitted, OrderStatus::Submitted, [
                'client_comment' => $comment,
                ...OrderTotals::columns($totals),
            ]);
            foreach ($priced as $line) {
                $existing = $current->get($line['product_id']);
                if ($existing === null) {
                    $order->lines()->create($this->lineAttributes($line));
                } elseif ($existing->requested_qty !== $line['requested_qty']) {
                    $existing->update(['requested_qty' => $line['requested_qty']]);
                }
            }
            $removed = $current->keys()->diff(array_keys($after))->all();
            if ($removed !== []) {
                OrderLine::query()->where('order_id', $order->id)->whereIn('product_id', $removed)->delete();
            }
            $this->history->record($order, OrderStatus::Submitted, OrderStatus::Submitted, OrderActor::Client, $by->id);

            activity('orders')
                ->performedOn($order)
                ->causedBy($by)
                ->event('updated')
                ->withProperties([
                    'number' => $order->number,
                    'version' => $order->version,
                    'before' => $before,
                    'after' => $this->auditLines($priced),
                    'total_ht' => Money::amount($totals['ht']),
                ])
                ->log(__('orders.audit.updated'));

            event(new OrderUpdated($order->id, $client->id, $order->region_id, $order->business_day_id, $order->version, $by->id));

            return $order->refresh();
        }, self::ATTEMPTS);
    }

    /**
     * Annulation par le client (§6.3) avant décision et avant l'heure
     * limite. Un client bloqué peut annuler (I-53).
     *
     * @throws ApiException NOT_FOUND, INVALID_TRANSITION, VERSION_CONFLICT, DAY_NOT_OPEN, CUTOFF_PASSED
     */
    public function cancel(User $by, string $publicId, int $version): Order
    {
        ['id' => $orderId, 'day' => $dayId] = $this->orderOf($by, $publicId);

        return DB::transaction(function () use ($by, $orderId, $dayId, $version): Order {
            $client = $this->lockClient($by);
            $businessDay = $this->locks->day($dayId);
            $order = $this->lockOrder($orderId);
            $this->workflow->assertCan($order->status, OrderStatus::Cancelled, OrderActor::Client);
            $this->versions->assert($order, $version);
            $this->window->assertOpen($this->window->forRegion($businessDay, $order->region_id));

            $this->versions->bump($order, $version, OrderStatus::Submitted, OrderStatus::Cancelled);
            $this->history->record($order, OrderStatus::Submitted, OrderStatus::Cancelled, OrderActor::Client, $by->id);

            activity('orders')
                ->performedOn($order)
                ->causedBy($by)
                ->event('cancelled')
                ->withProperties(['number' => $order->number, 'version' => $order->version])
                ->log(__('orders.audit.cancelled'));

            event(new OrderCancelled($order->id, $client->id, $order->region_id, $order->business_day_id, $order->version, $by->id));

            return $order->refresh();
        }, self::ATTEMPTS);
    }

    /**
     * Verrou du client, première instruction de la transaction : sous
     * REPEATABLE READ (MySQL), une lecture simple avant le verrou figerait
     * un instantané antérieur aux envois concurrents.
     */
    private function lockClient(User $by): Client
    {
        $client = $by->client_id === null ? null : $this->locks->client($by->client_id);
        if ($client === null) {
            throw new ApiException(ErrorCode::Forbidden, __('errors.FORBIDDEN'));
        }

        return $client;
    }

    /**
     * Commande du client et sa journée (sinon 403 ou 404), lues hors de la
     * transaction : elles ne changent jamais, et la transaction peut alors
     * verrouiller la journée avant la commande, dans l'ordre de la clôture
     * (journée → commandes), sans ouvrir son instantané avant les verrous.
     *
     * @return array{id: int, day: int}
     */
    private function orderOf(User $by, string $publicId): array
    {
        if ($by->client_id === null) {
            throw new ApiException(ErrorCode::Forbidden, __('errors.FORBIDDEN'));
        }
        $row = Order::isPublicId($publicId)
            ? Order::query()->where('client_id', $by->client_id)->where('public_id', strtoupper($publicId))->first(['id', 'business_day_id'])
            : null;
        if ($row === null) {
            throw new ApiException(ErrorCode::NotFound, __('orders.not_found'));
        }

        return ['id' => $row->id, 'day' => $row->business_day_id];
    }

    /** Commande du client (sinon 404), verrouillée après la journée. */
    private function lockOrder(int $orderId): Order
    {
        return $this->locks->order($orderId) ?? throw new ApiException(ErrorCode::NotFound, __('orders.not_found'));
    }

    private function assertActive(Client $client): void
    {
        if ($client->status !== ClientStatus::Active) {
            throw new ApiException(ErrorCode::ClientBlocked, __('orders.client_blocked'));
        }
    }

    /**
     * Contrôle des lignes (RG-C02, RG-C03) sur les disponibilités du client,
     * tout ou rien : produits indisponibles d'abord (422), puis quantités
     * au-dessus du disponible (409), chaque fois avec toutes les lignes en
     * cause. Prix HT figé et taux de TVA de chaque ligne.
     *
     * @param  list<array{product_id: int, quantity: int}>  $lines
     * @param  Collection<int, OrderLine>|null  $current  lignes actuelles (modification)
     * @return list<array{product_id: int, quantity: int, requested_qty: int, unit_price_ht: string, vat_rate: string}>
     */
    private function checkLines(RegionDay $regionDay, Client $client, array $lines, ?int $exceptOrderId = null, ?Collection $current = null): array
    {
        $regionDay->loadMissing('businessDay');
        $availability = $this->book->availability($regionDay, $client->id, $exceptOrderId);
        $vatRates = DB::table('products')->whereIn('id', array_column($lines, 'product_id'))->pluck('vat_rate', 'id');
        $products = DB::table('products')->whereIn('id', array_column($lines, 'product_id'))->pluck('name', 'id');

        $unavailable = [];
        foreach ($lines as $index => $line) {
            if (! isset($availability[$line['product_id']])) {
                $unavailable[] = ['line' => $index, 'product_id' => $line['product_id']];
            }
        }
        if ($unavailable !== []) {
            throw new ApiException(ErrorCode::ProductNotAvailable, trans_choice('orders.products_unavailable', count($unavailable), [
                'count' => count($unavailable),
            ]), ['violations' => $unavailable]);
        }

        $exceeded = [];
        foreach ($lines as $index => $line) {
            $row = $availability[$line['product_id']];
            if ($line['quantity'] > $row['available']) {
                $exceeded[] = [
                    'line' => $index,
                    'product_id' => $line['product_id'],
                    'requested' => $line['quantity'],
                    // Sans borne, le reste de la région n'est jamais montré au client (RG-C09, Q-12).
                    'max_allowed' => $row['limit'] === null ? null : $row['available'],
                ];
            }
        }
        if ($exceeded !== []) {
            $first = $exceeded[0];
            $detail = count($exceeded) > 1
                ? __('orders.limits_exceeded', ['count' => count($exceeded)])
                : ($first['max_allowed'] === null
                    ? __('orders.limit_exceeded_unknown', ['product' => $products->get($first['product_id'])])
                    : __('orders.limit_exceeded_one', ['product' => $products->get($first['product_id']), 'max' => $first['max_allowed']]));
            throw new ApiException(ErrorCode::ClientLimitExceeded, $detail, ['violations' => $exceeded]);
        }

        return array_map(function (array $line) use ($availability, $vatRates, $current): array {
            $kept = $current?->get($line['product_id']);

            return [
                'product_id' => $line['product_id'],
                'quantity' => $line['quantity'],
                'requested_qty' => $line['quantity'],
                // RG-P03 : prix figé à l'envoi ; un produit gardé à la modification garde le sien.
                'unit_price_ht' => $kept?->unit_price_ht ?? $availability[$line['product_id']]['price_ht'],
                'vat_rate' => $kept?->vat_rate ?? (string) $vatRates->get($line['product_id']),
            ];
        }, array_values($lines));
    }

    /**
     * Totaux en centimes sur les quantités demandées (RG-P04, OrderTotals).
     *
     * @param  list<array{requested_qty: int, unit_price_ht: string, vat_rate: string}>  $lines
     * @return array{ht: int, vat: int, ttc: int}
     */
    private function totals(array $lines): array
    {
        return OrderTotals::of(array_map(fn (array $line) => [
            'unit_price_ht' => $line['unit_price_ht'],
            'vat_rate' => $line['vat_rate'],
            'qty' => $line['requested_qty'],
        ], $lines));
    }

    /** Montant minimum de commande HT (RG-C06), désactivé par défaut. */
    private function assertMinimum(int $htCents): void
    {
        $minimum = $this->settings->get('orders.min_amount');
        if ($minimum === null || $minimum === '') {
            return;
        }
        $minimumCents = Money::cents($minimum);
        if ($htCents < $minimumCents) {
            throw new ApiException(ErrorCode::OrderBelowMinimum, __('orders.below_minimum', [
                'min' => number_format(Money::amount($minimumCents), 2, ',', ' '),
                'total' => number_format(Money::amount($htCents), 2, ',', ' '),
            ]), ['min_amount_ht' => Money::amount($minimumCents), 'total_ht' => Money::amount($htCents)]);
        }
    }

    private function comment(?string $comment): ?string
    {
        $comment = $comment === null ? null : trim($comment);

        return $comment === '' ? null : $comment;
    }

    /**
     * @param  list<array{product_id: int, requested_qty: int}>  $lines
     * @return array<int, int> produit → quantité demandée
     */
    private function auditLines(array $lines): array
    {
        $quantities = [];
        foreach ($lines as $line) {
            $quantities[$line['product_id']] = $line['requested_qty'];
        }
        ksort($quantities);

        return $quantities;
    }

    /**
     * Lignes de l'envoi en une instruction (jusqu'à 200, I-60).
     *
     * @param  list<array{product_id: int, requested_qty: int, unit_price_ht: string, vat_rate: string}>  $lines
     */
    private function insertLines(Order $order, array $lines): void
    {
        $now = now();
        OrderLine::query()->insert(array_map(fn (array $line) => [
            ...$this->lineAttributes($line),
            'order_id' => $order->id,
            'created_at' => $now,
            'updated_at' => $now,
        ], $lines));
    }

    /**
     * @param  array{product_id: int, requested_qty: int, unit_price_ht: string, vat_rate: string}  $line
     * @return array{product_id: int, requested_qty: int, unit_price_ht: string, vat_rate: string}
     */
    private function lineAttributes(array $line): array
    {
        return [
            'product_id' => $line['product_id'],
            'requested_qty' => $line['requested_qty'],
            'unit_price_ht' => $line['unit_price_ht'],
            'vat_rate' => $line['vat_rate'],
        ];
    }
}
