<?php

namespace App\Domain\Orders;

use App\Domain\Access\Perimeter;
use App\Domain\Quotas\QuotaLedger;
use App\Domain\Reasons\ReasonCodes;
use App\Domain\Reasons\ReasonType;
use App\Events\OrderCancelledByAdv;
use App\Events\OrderShippingUpdated;
use App\Events\OrdersProcessed;
use App\Http\Problems\ApiException;
use App\Http\Problems\ErrorCode;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderStatusHistory;
use App\Models\RegionDay;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Traitement des commandes validées par l'ADV (§5.6, §6.3) : statuts de
 * traitement unitaires ou en masse (RG-A02, I-78), quantités expédiées et
 * bon de livraison (RG-A03, I-77, I-79), annulation exceptionnelle (RG-V07,
 * I-75, I-76). Aucune heure limite ni statut de journée ne s'applique : le
 * traitement continue après la clôture (§4.4).
 *
 * Verrous (I-67) : journée(s) en partagé puis commande(s) FOR UPDATE, par
 * identifiant croissant ; l'annulation, qui rend V(c,p) au client, prend le
 * client d'abord. Historique avec l'acteur ADV, journal `orders`,
 * événements après le commit.
 */
final class AdvOrders
{
    /** Tentatives d'une transaction interrompue par un interblocage (MySQL). */
    private const ATTEMPTS = 3;

    /** Commandes d'un même changement de statut en masse. */
    public const MAX_BULK = 100;

    /** Commentaire d'une annulation. */
    public const DECISION_COMMENT_MAX = 500;

    /** Référence du bon de livraison. */
    public const DELIVERY_NOTE_MAX = 50;

    /** Statut de traitement → horodatage (§6.3). */
    private const TIMESTAMPS = [
        'IN_PREPARATION' => 'prep_started_at',
        'PREPARED' => 'prepared_at',
        'SHIPPED' => 'shipped_at',
    ];

    public function __construct(
        private readonly OrderWorkflow $workflow,
        private readonly OrderLocks $locks,
        private readonly OrderVersion $versions,
        private readonly OrderHistory $history,
        private readonly QuotaLedger $ledger,
        private readonly ReasonCodes $reasons,
        private readonly Perimeter $perimeter,
    ) {}

    /**
     * Statuts de traitement atteignables par l'ADV (Livrée, V2, exclue).
     *
     * @return list<OrderStatus>
     */
    public static function processingStatuses(): array
    {
        return [OrderStatus::InPreparation, OrderStatus::Prepared, OrderStatus::Shipped];
    }

    /**
     * Commandes visibles de l'ADV dans sa file (I-90) : Validées et
     * suivantes, et Annulées après validation ; jamais une commande envoyée.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public static function visible(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereIn('orders.status', OrderStatus::values(OrderStatus::validated()))
            ->orWhere(fn (Builder $q) => $q->where('orders.status', OrderStatus::Cancelled->value)->whereNotNull('orders.decided_at')));
    }

    /**
     * Passage d'une ou plusieurs commandes au statut suivant (une étape à la
     * fois : chaque statut cible n'a qu'un statut d'origine). Les commandes
     * sont résolues hors de la transaction (périmètre RG-S05 à la journée de
     * chaque commande, file de l'ADV seulement) : une commande envoyée n'est
     * jamais verrouillée. Chaque commande porte sa version ; un renvoi déjà
     * appliqué (statut atteint, version suivante) compte comme appliqué. À
     * Préparée et à Expédiée, la quantité expédiée prend la quantité validée
     * sur les lignes non encore saisies (I-77).
     *
     * @param  list<array{id: string, version: int}>  $items
     *
     * @throws ValidationException `status` hors des statuts de traitement
     */
    public function transition(User $by, OrderStatus $to, array $items): BulkResult
    {
        if (! isset(self::TIMESTAMPS[$to->value])) {
            throw ValidationException::withMessages(['status' => [__('orders.adv.invalid_status')]]);
        }
        /** @var array<string, array{id: string, version: int}> $requested ULID en majuscules → élément (premier gardé) */
        $requested = [];
        foreach ($items as $item) {
            $requested[strtoupper((string) $item['id'])] ??= ['id' => (string) $item['id'], 'version' => (int) $item['version']];
        }
        $ids = array_values(array_filter(array_keys($requested), fn (string $id) => Order::isPublicId($id)));
        $found = $ids === [] ? collect() : self::visible($this->perimeter->constrainOrders(Order::query(), $by))
            ->whereIn('orders.public_id', $ids)
            ->get(['orders.id', 'orders.public_id', 'orders.business_day_id'])
            ->keyBy('public_id');

        return DB::transaction(function () use ($by, $to, $requested, $found): BulkResult {
            $this->locks->days($found->pluck('business_day_id'));
            $orders = $this->locks->orders($found->pluck('id'));

            /** @var list<array{0: string, 1: Order|array<string, mixed>}> $results dans l'ordre de la demande */
            $results = [];
            /** @var list<Order> $changed */
            $changed = [];
            foreach ($requested as $publicId => $item) {
                $order = $orders->get($found->get($publicId)?->id ?? 0);
                if ($order === null) {
                    $results[] = ['refused', ['id' => $item['id'], 'number' => null, 'code' => ErrorCode::NotFound->value, 'detail' => __('orders.not_found'), 'version' => null]];
                } elseif ($order->status === $to && $order->version === $item['version'] + 1) {
                    $results[] = ['applied', $order];
                } elseif (! $this->workflow->can($order->status, $to, OrderActor::Adv)) {
                    $results[] = ['refused', $this->refusal($order, ErrorCode::InvalidTransition, __('orders.invalid_transition', [
                        'from' => $order->status->label(),
                        'to' => $to->label(),
                    ]))];
                } elseif ($order->version !== $item['version']) {
                    $results[] = ['refused', $this->refusal($order, ErrorCode::VersionConflict, __('orders.version_conflict'))];
                } else {
                    $results[] = ['applied', $order];
                    $changed[] = $order;
                }
            }
            if ($changed !== []) {
                $this->apply($by, $to, $changed);
            }

            $applied = [];
            $refused = [];
            foreach ($results as [$kind, $result]) {
                if ($kind === 'applied') {
                    $applied[] = ['id' => $result->public_id, 'number' => $result->number, 'status' => $result->status->value, 'version' => $result->version];
                } else {
                    $refused[] = $result;
                }
            }

            return new BulkResult($applied, $refused);
        }, self::ATTEMPTS);
    }

    /**
     * Quantités expédiées (ou préparées) et motifs d'écart, bon de livraison
     * (RG-A03, I-77, I-79). Quantités modifiables En préparation et Préparée ;
     * Expédiée : seul le bon de livraison change. `$lines` : tout ou partie
     * des lignes ; `$refGiven` : le bon de livraison est transmis (null
     * l'efface). Version incrémentée, sans ligne d'historique ; rien de
     * changé : commande rendue telle quelle. Le quota n'est jamais touché
     * (Q-16).
     *
     * @param  list<array{product_id: int, shipped_qty: int, reason_code?: ?string}>|null  $lines
     *
     * @throws ApiException INVALID_TRANSITION, VERSION_CONFLICT
     * @throws ValidationException `lines.N.product_id`, `lines.N.shipped_qty`, `lines.N.reason_code`, `delivery_note_ref`
     */
    public function updateShipping(User $by, Order $order, int $version, ?array $lines, ?string $deliveryNoteRef, bool $refGiven): Order
    {
        $this->reasons->preload();

        return DB::transaction(function () use ($by, $order, $version, $lines, $deliveryNoteRef, $refGiven): Order {
            $this->locks->day($order->business_day_id);
            $locked = $this->lockOrder($order->id);
            $lines = array_values($lines ?? []);
            if (! in_array($locked->status, self::processingStatuses(), true)) {
                throw new ApiException(ErrorCode::InvalidTransition, __('orders.adv.shipping_not_editable', ['status' => $locked->status->label()]));
            }
            if ($locked->status === OrderStatus::Shipped && $lines !== []) {
                throw new ApiException(ErrorCode::InvalidTransition, __('orders.adv.shipped_frozen'));
            }
            $this->versions->assert($locked, $version);

            $current = $locked->lines()->with('product:id,sku')->get()->keyBy('product_id');
            $changes = $this->checkShipping($current, $lines);
            $before = $locked->delivery_note_ref;
            $ref = $refGiven ? ReasonCodes::clean($deliveryNoteRef) : $before;
            if ($ref !== null && mb_strlen($ref) > self::DELIVERY_NOTE_MAX) {
                throw ValidationException::withMessages(['delivery_note_ref' => [__('orders.adv.delivery_note_max', ['max' => self::DELIVERY_NOTE_MAX])]]);
            }
            if ($changes === [] && $ref === $before) {
                return $locked;
            }

            $this->versions->bump($locked, $version, $locked->status, $locked->status, $ref === $before ? [] : ['delivery_note_ref' => $ref]);
            $now = now();
            foreach ($changes as $change) {
                OrderLine::query()->whereKey($change['line']->id)->update([
                    'shipped_qty' => $change['shipped'],
                    'shipping_gap_reason_code' => $change['reason'],
                    'updated_at' => $now,
                ]);
            }

            activity('orders')
                ->performedOn($locked)
                ->causedBy($by)
                ->event('shipping_updated')
                ->withProperties([
                    'number' => $locked->number,
                    'version' => $locked->version,
                    'lines' => [
                        'before' => array_map(fn (array $change) => [
                            'sku' => $change['line']->product?->sku,
                            'shipped' => $change['line']->shipped_qty,
                            'reason' => $change['line']->shipping_gap_reason_code,
                        ], $changes),
                        'after' => array_map(fn (array $change) => [
                            'sku' => $change['line']->product?->sku,
                            'shipped' => $change['shipped'],
                            'reason' => $change['reason'],
                        ], $changes),
                    ],
                    'delivery_note_ref' => ['before' => $before, 'after' => $ref],
                ])
                ->log(__('orders.audit.shipping_updated'));

            event(new OrderShippingUpdated($locked->id, $locked->business_day_id, $locked->version, $by->id));

            return $locked->refresh();
        }, self::ATTEMPTS);
    }

    /**
     * Annulation exceptionnelle (RG-V07, §6.3, T08) d'une commande Validée ou
     * En préparation, motif CANCELLATION actif (commentaire si le motif
     * l'exige). Le motif et le commentaire vont dans `decision_reason_code` /
     * `decision_comment`, la validation reste dans `decided_*`, quantités
     * validées et totaux gardés (I-74, I-75). V(r,p) est restitué par
     * QuotaLedger, même après la clôture (H-06, I-76) ; V(c,p) suit le statut.
     *
     * @throws ApiException INVALID_TRANSITION (Préparée ou au-delà), VERSION_CONFLICT
     * @throws ValidationException `reason_code`, `comment`
     */
    public function cancel(User $by, Order $order, int $version, string $reasonCode, ?string $comment): Order
    {
        $this->reasons->preload();

        return DB::transaction(function () use ($by, $order, $version, $reasonCode, $comment): Order {
            $this->locks->client($order->client_id);
            $this->locks->day($order->business_day_id);
            $locked = $this->lockOrder($order->id);
            if ($locked->status === OrderStatus::Cancelled && $locked->version === $version + 1 && $this->cancelledBy($locked, $by)) {
                return $locked;
            }

            $from = $locked->status;
            $this->workflow->assertCan($from, OrderStatus::Cancelled, OrderActor::Adv);
            $this->versions->assert($locked, $version);
            $choice = $this->reasons->assertChoice(ReasonType::Cancellation, $reasonCode, $comment, self::DECISION_COMMENT_MAX);

            $this->versions->bump($locked, $version, $from, OrderStatus::Cancelled, [
                'decision_reason_code' => $choice['code'],
                'decision_comment' => $choice['comment'],
            ]);

            $lines = $locked->lines()->with('product:id,sku')->get();
            $released = $lines->filter(fn (OrderLine $line) => (int) $line->validated_qty > 0);
            if ($released->isNotEmpty()) {
                $regionDayId = RegionDay::query()
                    ->where('business_day_id', $locked->business_day_id)
                    ->where('region_id', $locked->region_id)
                    ->value('id') ?? throw new LogicException("Journée régionale introuvable pour la commande {$locked->id}.");
                $this->ledger->release((int) $regionDayId, $released->mapWithKeys(fn (OrderLine $line) => [$line->product_id => (int) $line->validated_qty])->all());
            }
            $this->history->record($locked, $from, OrderStatus::Cancelled, OrderActor::Adv, $by->id, $choice['comment']);

            activity('orders')
                ->performedOn($locked)
                ->causedBy($by)
                ->event('cancelled_by_adv')
                ->withProperties([
                    'number' => $locked->number,
                    'version' => $locked->version,
                    'reason' => $choice['code'],
                    'comment' => $choice['comment'],
                    'released' => $released->map(fn (OrderLine $line) => ['sku' => $line->product?->sku, 'qty' => (int) $line->validated_qty])->values()->all(),
                ])
                ->log(__('orders.audit.cancelled_by_adv'));

            event(new OrderCancelledByAdv($locked->id, $locked->client_id, $locked->region_id, $locked->business_day_id, $locked->version, $by->id));

            return $locked->refresh();
        }, self::ATTEMPTS);
    }

    /**
     * Écritures d'un changement de statut en masse, sous les verrous : une
     * instruction par statut d'origine, quantités expédiées par défaut,
     * historique en une instruction, journal par commande (lot commun),
     * un événement.
     *
     * @param  list<Order>  $orders  verrouillées, contrôlées
     */
    private function apply(User $by, OrderStatus $to, array $orders): void
    {
        $now = now();
        $column = self::TIMESTAMPS[$to->value];
        $batch = strtoupper((string) Str::ulid());

        foreach (collect($orders)->groupBy(fn (Order $order) => $order->status->value) as $from => $group) {
            $from = OrderStatus::from($from);
            $ids = $group->map(fn (Order $order) => $order->id)->all();
            Order::query()
                ->whereKey($ids)
                ->where('status', $from->value)
                ->update(['status' => $to->value, 'version' => DB::raw('version + 1'), $column => $now, 'updated_at' => $now]);
            if ($to === OrderStatus::Prepared || $to === OrderStatus::Shipped) {
                OrderLine::query()
                    ->whereIn('order_id', $ids)
                    ->whereNull('shipped_qty')
                    ->update(['shipped_qty' => DB::raw('validated_qty'), 'updated_at' => $now]);
            }
            $group->each(fn (Order $order) => $order->forceFill(['status' => $to, 'version' => $order->version + 1, $column => $now]));
            $this->history->recordMany($group, $from, $to, OrderActor::Adv, $by->id);

            foreach ($group as $order) {
                activity('orders')
                    ->performedOn($order)
                    ->causedBy($by)
                    ->event('status_changed')
                    ->withProperties([
                        'number' => $order->number,
                        'from' => $from->value,
                        'to' => $to->value,
                        'version' => $order->version,
                        'batch' => $batch,
                    ])
                    ->log(__('orders.audit.status_changed'));
            }
        }

        event(new OrdersProcessed(array_map(fn (Order $order) => [
            'orderId' => $order->id,
            'clientId' => $order->client_id,
            'regionId' => $order->region_id,
            'businessDayId' => $order->business_day_id,
            'version' => $order->version,
        ], $orders), $to, $by->id, $batch));
    }

    /**
     * Lignes d'expédition transmises (RG-A03) : produit de la commande, une
     * fois ; 0 ≤ expédié ≤ validé ; motif SHIPPING_GAP actif si et seulement
     * si l'expédié est sous le validé (une ligne validée à 0 s'expédie à 0,
     * sans motif). Rend les seules lignes qui changent.
     *
     * @param  Collection<int, OrderLine>  $current  produit → ligne
     * @param  list<array{product_id: int, shipped_qty: int, reason_code?: ?string}>  $lines
     * @return list<array{line: OrderLine, shipped: int, reason: ?string}>
     *
     * @throws ValidationException
     */
    private function checkShipping(Collection $current, array $lines): array
    {
        $errors = [];
        $changes = [];
        $seen = [];
        foreach ($lines as $index => $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            /** @var OrderLine|null $orderLine */
            $orderLine = $current->get($productId);
            if ($orderLine === null || isset($seen[$productId])) {
                $errors["lines.{$index}.product_id"] = [__($orderLine === null ? 'orders.adv.unknown_line' : 'orders.adv.duplicate_line')];

                continue;
            }
            $seen[$productId] = true;
            $validated = (int) $orderLine->validated_qty;
            $shipped = filter_var($line['shipped_qty'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
            if ($shipped === null || $shipped < 0 || $shipped > $validated) {
                $errors["lines.{$index}.shipped_qty"] = [__('orders.adv.shipped_range', ['validated' => $validated])];

                continue;
            }
            $reason = ReasonCodes::clean($line['reason_code'] ?? null);
            $gap = $shipped < $validated;
            if ($gap && $reason === null) {
                $errors["lines.{$index}.reason_code"] = [__('orders.adv.gap_reason_required')];
            } elseif (! $gap && $reason !== null) {
                $errors["lines.{$index}.reason_code"] = [__('orders.adv.gap_reason_prohibited')];
            } elseif ($gap && ! $this->reasons->isActive(ReasonType::ShippingGap, $reason)) {
                $errors["lines.{$index}.reason_code"] = [__('reasons.unknown')];
            }
            if ($shipped !== $orderLine->shipped_qty || $reason !== $orderLine->shipping_gap_reason_code) {
                $changes[] = ['line' => $orderLine, 'shipped' => $shipped, 'reason' => $reason];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $changes;
    }

    /**
     * @return array{id: string, number: ?string, code: string, detail: string, version: ?int}
     */
    private function refusal(Order $order, ErrorCode $code, string $detail): array
    {
        return ['id' => $order->public_id, 'number' => $order->number, 'code' => $code->value, 'detail' => $detail, 'version' => $order->version];
    }

    /** Annulation déjà enregistrée par le même auteur (renvoi, I-92). */
    private function cancelledBy(Order $order, User $by): bool
    {
        return OrderStatusHistory::query()
            ->where('order_id', $order->id)
            ->where('version', $order->version)
            ->where('to_status', OrderStatus::Cancelled->value)
            ->where('user_id', $by->id)
            ->exists();
    }

    private function lockOrder(int $orderId): Order
    {
        return $this->locks->order($orderId) ?? throw new ApiException(ErrorCode::NotFound, __('orders.not_found'));
    }
}
