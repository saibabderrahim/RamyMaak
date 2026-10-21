<?php

namespace App\Domain\Orders;

use App\Domain\Days\RegionDayStatus;
use App\Domain\Quotas\QuotaExceeded;
use App\Domain\Quotas\QuotaLedger;
use App\Domain\Reasons\ReasonCodes;
use App\Domain\Reasons\ReasonType;
use App\Domain\Referentials\ClientStatus;
use App\Events\OrderRejected;
use App\Events\OrderValidated;
use App\Http\Problems\ApiException;
use App\Http\Problems\ErrorCode;
use App\Models\Client;
use App\Models\ClientLimit;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\RegionDay;
use App\Models\RegionQuota;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Décision du régional sur une commande envoyée (§5.5, §5.8, §6.3) :
 * validation, avec ou sans ajustement, et rejet motivé. La commande est
 * résolue avant par la requête (droits, puis 404 hors périmètre à la
 * journée de la commande) ; tout le reste est contrôlé ici, sous verrou.
 *
 * Validation, une transaction (I-67, I-68) : client → journée (partagé) →
 * journée régionale (partagé : une borne ne peut pas être publiée sous V
 * en même temps, I-2) → commande ; puis INVALID_TRANSITION, VERSION_CONFLICT,
 * DAY_NOT_OPEN, CUTOFF_PASSED (heure bloquante, RG-V08), CLIENT_BLOCKED, sens
 * des lignes (422) ; version conditionnelle (§5.8 étape 1) ; bornes du
 * client FOR UPDATE puis compteurs (QuotaLedger), par produit croissant,
 * contrôle de toutes les lignes (RG-V02, RG-V03 : tous les dépassements,
 * I-69) et mise à jour conditionnelle des compteurs (étape 2) ; lignes,
 * totaux sur les quantités validées (RG-P04), historique, journal ;
 * événements après le commit (étape 4). Le serveur ne plafonne jamais une
 * quantité de lui-même (D-06).
 *
 * Un renvoi de la même décision (déjà appliquée par le même auteur, version
 * suivante) rend l'état actuel sans effet (I-92).
 */
final class OrderDecisions
{
    /** Tentatives d'une transaction interrompue par un interblocage (MySQL). */
    private const ATTEMPTS = 3;

    /** Commentaire d'ajustement d'une ligne (I-73). */
    public const ADJUSTMENT_COMMENT_MAX = 200;

    /** Commentaire d'un rejet. */
    public const DECISION_COMMENT_MAX = 500;

    public function __construct(
        private readonly OrderWorkflow $workflow,
        private readonly OrderLocks $locks,
        private readonly OrderVersion $versions,
        private readonly OrderHistory $history,
        private readonly OrderUsage $usage,
        private readonly QuotaLedger $ledger,
        private readonly ReasonCodes $reasons,
        private readonly ValidationWindow $window,
    ) {}

    /**
     * Validation (RG-V02 à RG-V08). Toutes les lignes de la commande, une
     * fois chacune ; motif d'ajustement (et commentaire si le motif l'exige)
     * sur les lignes réduites seulement (I-72).
     *
     * @param  list<array{product_id: int, validated_qty: int, reason_code?: ?string, comment?: ?string}>  $lines
     *
     * @throws ApiException INVALID_TRANSITION, VERSION_CONFLICT, DAY_NOT_OPEN, CUTOFF_PASSED, CLIENT_BLOCKED,
     *                      REGION_QUOTA_EXCEEDED, CLIENT_LIMIT_EXCEEDED
     * @throws ValidationException `lines`, `lines.N.validated_qty`, `lines.N.reason_code`, `lines.N.comment`
     */
    public function validate(User $by, Order $order, int $version, array $lines): Order
    {
        // Avant la transaction (I-67) : paramètre lu en cache et motifs.
        $blocking = $this->window->blocking();
        $this->reasons->preload();

        return DB::transaction(function () use ($by, $order, $version, $lines, $blocking): Order {
            $client = $this->lockClient($order);
            $businessDay = $this->locks->day($order->business_day_id);
            $regionDay = $this->locks->regionDay($order->business_day_id, $order->region_id);
            $locked = $this->lockOrder($order->id);
            if ($this->isReplay($locked, OrderStatus::Validated, $version, $by)) {
                return $locked;
            }

            $this->workflow->assertCan($locked->status, OrderStatus::Validated, OrderActor::Regional);
            $this->versions->assert($locked, $version);
            if (! $businessDay->isOpen() || $regionDay === null || $regionDay->status === RegionDayStatus::Closed) {
                throw new ApiException(ErrorCode::DayNotOpen, __('orders.decision.day_closed'));
            }
            $window = $this->window->state($businessDay, $regionDay, $blocking);
            if ($window->blocks()) {
                throw new ApiException(ErrorCode::CutoffPassed, __('orders.decision.cutoff_passed', ['time' => $window->cutoff()]), [
                    'cutoff' => $window->cutoff(),
                ]);
            }
            if ($client->status !== ClientStatus::Active) {
                throw new ApiException(ErrorCode::ClientBlocked, __('orders.decision.client_blocked'));
            }

            // Lecture simple des lignes, après tous les verrous.
            $current = $locked->lines()->with('product:id,sku')->get()->keyBy('product_id');
            $decided = $this->checkLines($current, $lines);
            $adjusted = count(array_filter($decided, fn (array $line) => $line['qty'] < $line['requested']));
            $totals = OrderTotals::of(array_map(fn (array $line) => [
                'unit_price_ht' => $line['line']->unit_price_ht,
                'vat_rate' => $line['line']->vat_rate,
                'qty' => $line['qty'],
            ], $decided));

            // §5.8 étape 1.
            $this->versions->bump($locked, $version, OrderStatus::Submitted, OrderStatus::Validated, [
                'decided_at' => now(),
                'decided_by' => $by->id,
                'is_adjusted' => $adjusted > 0,
                ...OrderTotals::columns($totals),
            ]);

            // §5.8 étape 2 : bornes puis compteurs, par produit croissant ; une ligne à 0 n'y touche pas.
            $quantities = [];
            foreach ($decided as $line) {
                if ($line['qty'] > 0) {
                    $quantities[$line['product_id']] = $line['qty'];
                }
            }
            ksort($quantities);
            $violations = $this->checkCeilings($regionDay, $client, $locked, $decided, $quantities);
            try {
                $this->ledger->reserve($regionDay->id, $quantities);
            } catch (QuotaExceeded $e) {
                // Filet (le contrôle préalable sous verrou l'attrape normalement).
                throw new ApiException(ErrorCode::RegionQuotaExceeded, trans_choice('orders.decision.region_exceeded', 1, ['count' => 1]), [
                    'violations' => [[...$violations[$e->productId], 'cause' => 'REGION_QUOTA']],
                ]);
            }

            $now = now();
            foreach ($decided as $line) {
                OrderLine::query()->whereKey($line['line']->id)->update([
                    'validated_qty' => $line['qty'],
                    'adjustment_reason_code' => $line['reason'],
                    'adjustment_comment' => $line['comment'],
                    'updated_at' => $now,
                ]);
            }
            $this->history->record($locked, OrderStatus::Submitted, OrderStatus::Validated, OrderActor::Regional, $by->id);

            activity('orders')
                ->performedOn($locked)
                ->causedBy($by)
                ->event('validated')
                ->withProperties([
                    'number' => $locked->number,
                    'version' => $locked->version,
                    'lines' => array_map(fn (array $line) => [
                        'sku' => $line['line']->product?->sku,
                        'requested' => $line['requested'],
                        'validated' => $line['qty'],
                        'reason' => $line['reason'],
                        'comment' => $line['comment'],
                    ], $decided),
                    'adjusted_lines' => $adjusted,
                    'total_ht' => Money::amount($totals['ht']),
                ])
                ->log(__('orders.audit.validated'));

            event(new OrderValidated($locked->id, $client->id, $locked->region_id, $locked->business_day_id, $locked->version, $by->id, $adjusted));

            return $locked->refresh();
        }, self::ATTEMPTS);
    }

    /**
     * Rejet motivé (RG-V05, §6.3) : motif REJECTION actif, commentaire si le
     * motif l'exige. Ni l'heure limite de validation ni le statut du client
     * ne l'empêchent (I-70, I-71) ; seule une journée clôturée le refuse. Les
     * quantités validées restent nulles et les totaux ceux de la demande
     * (I-74) ; aucun compteur n'est touché.
     *
     * @throws ApiException INVALID_TRANSITION, VERSION_CONFLICT, DAY_NOT_OPEN
     * @throws ValidationException `reason_code`, `comment`
     */
    public function reject(User $by, Order $order, int $version, string $reasonCode, ?string $comment): Order
    {
        $this->reasons->preload();

        return DB::transaction(function () use ($by, $order, $version, $reasonCode, $comment): Order {
            $client = $this->lockClient($order);
            $businessDay = $this->locks->day($order->business_day_id);
            $locked = $this->lockOrder($order->id);
            if ($this->isReplay($locked, OrderStatus::Rejected, $version, $by)) {
                return $locked;
            }

            $this->workflow->assertCan($locked->status, OrderStatus::Rejected, OrderActor::Regional);
            $this->versions->assert($locked, $version);
            if (! $businessDay->isOpen()) {
                throw new ApiException(ErrorCode::DayNotOpen, __('orders.decision.day_closed'));
            }
            $choice = $this->reasons->assertChoice(ReasonType::Rejection, $reasonCode, $comment, self::DECISION_COMMENT_MAX);

            $this->versions->bump($locked, $version, OrderStatus::Submitted, OrderStatus::Rejected, [
                'decided_at' => now(),
                'decided_by' => $by->id,
                'decision_reason_code' => $choice['code'],
                'decision_comment' => $choice['comment'],
            ]);
            $this->history->record($locked, OrderStatus::Submitted, OrderStatus::Rejected, OrderActor::Regional, $by->id, $choice['comment']);

            activity('orders')
                ->performedOn($locked)
                ->causedBy($by)
                ->event('rejected')
                ->withProperties([
                    'number' => $locked->number,
                    'version' => $locked->version,
                    'reason' => $choice['code'],
                    'comment' => $choice['comment'],
                ])
                ->log(__('orders.audit.rejected'));

            event(new OrderRejected($locked->id, $client->id, $locked->region_id, $locked->business_day_id, $locked->version, $by->id));

            return $locked->refresh();
        }, self::ATTEMPTS);
    }

    /**
     * Sens des lignes (RG-V04, RG-V05, I-72, I-73), contrôlé après les
     * verrous : une modification du client entre-temps est un conflit de
     * version, pas une erreur de saisie. Exactement les produits de la
     * commande ; 0 ≤ validé ≤ demandé ; motif ADJUSTMENT actif si et
     * seulement si la ligne est réduite ; commentaire seulement sur une ligne
     * réduite, exigé si le motif l'exige ; pas toutes les lignes à 0.
     *
     * @param  Collection<int, OrderLine>  $current  produit → ligne
     * @param  list<array{product_id: int, validated_qty: int, reason_code?: ?string, comment?: ?string}>  $lines
     * @return list<array{index: int, product_id: int, requested: int, qty: int, reason: ?string, comment: ?string, line: OrderLine}>
     *
     * @throws ValidationException
     */
    private function checkLines(Collection $current, array $lines): array
    {
        // Quantité entière (« 12 » envoyé en texte compris), sinon null.
        $lines = array_map(fn (array $line) => [
            ...$line,
            'validated_qty' => filter_var($line['validated_qty'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
        ], array_values($lines));
        $sent = array_map(fn (array $line) => (int) ($line['product_id'] ?? 0), $lines);
        $expected = array_map('intval', $current->keys()->all());
        sort($sent);
        sort($expected);
        if ($sent !== $expected) {
            throw ValidationException::withMessages(['lines' => [__('orders.decision.lines_mismatch')]]);
        }
        if (array_filter($lines, fn (array $line) => ($line['validated_qty'] ?? null) !== 0) === []) {
            throw ValidationException::withMessages(['lines' => [__('orders.decision.all_zero')]]);
        }

        $errors = [];
        $decided = [];
        foreach ($lines as $index => $line) {
            /** @var OrderLine $orderLine */
            $orderLine = $current->get((int) $line['product_id']);
            $requested = $orderLine->requested_qty;
            $qty = $line['validated_qty'];
            $reason = ReasonCodes::clean($line['reason_code'] ?? null);
            $comment = ReasonCodes::clean($line['comment'] ?? null);
            if ($qty === null || $qty < 0 || $qty > $requested) {
                $errors["lines.{$index}.validated_qty"] = [__('orders.decision.quantity_range', ['requested' => $requested])];

                continue;
            }

            $reduced = $qty < $requested;
            if ($reduced && $reason === null) {
                $errors["lines.{$index}.reason_code"] = [__('orders.decision.reason_required')];
            } elseif (! $reduced && $reason !== null) {
                $errors["lines.{$index}.reason_code"] = [__('orders.decision.reason_prohibited')];
            } elseif ($reduced && ! $this->reasons->isActive(ReasonType::Adjustment, $reason)) {
                $errors["lines.{$index}.reason_code"] = [__('reasons.unknown')];
            }
            if (! $reduced && $comment !== null) {
                $errors["lines.{$index}.comment"] = [__('orders.decision.comment_prohibited')];
            } elseif ($comment !== null && mb_strlen($comment) > self::ADJUSTMENT_COMMENT_MAX) {
                $errors["lines.{$index}.comment"] = [__('reasons.comment_max', ['max' => self::ADJUSTMENT_COMMENT_MAX])];
            } elseif ($reduced && $comment === null && $this->reasons->requiresComment(ReasonType::Adjustment, $reason)) {
                $errors["lines.{$index}.comment"] = [__('reasons.comment_required')];
            }

            $decided[] = [
                'index' => $index,
                'product_id' => $orderLine->product_id,
                'requested' => $requested,
                'qty' => $qty,
                'reason' => $reduced ? $reason : null,
                'comment' => $reduced ? $comment : null,
                'line' => $orderLine,
            ];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $decided;
    }

    /**
     * Plafonds (RG-V02, RG-V03, §5.8 étape 2), après la version : bornes du
     * client FOR UPDATE puis compteurs de la région (QuotaLedger::lock), par
     * produit croissant ; V(c,p) relu après les verrous, hors de cette
     * commande. Toutes les lignes en cause sont rendues, avec le maximum
     * validable (I-69) : REGION_QUOTA_EXCEEDED dès qu'une ligne dépasse le
     * reste de la région, sinon CLIENT_LIMIT_EXCEEDED.
     *
     * @param  list<array{index: int, product_id: int, requested: int, qty: int, reason: ?string, comment: ?string, line: OrderLine}>  $decided
     * @param  array<int, int>  $quantities  produit → quantité > 0, par produit croissant
     * @return array<int, array{line: int, product_id: int, sku: ?string, requested: int, quota: int, validated: int, limit: ?int, client_validated: int, max_allowed: int}> produit → ligne contrôlée (violation sans la cause)
     *
     * @throws ApiException REGION_QUOTA_EXCEEDED, CLIENT_LIMIT_EXCEEDED
     */
    private function checkCeilings(RegionDay $regionDay, Client $client, Order $order, array $decided, array $quantities): array
    {
        $productIds = array_keys($quantities);
        $limits = ClientLimit::query()
            ->where('region_day_id', $regionDay->id)
            ->where('client_id', $client->id)
            ->whereIn('product_id', $productIds)
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get(['id', 'product_id', 'max_qty'])
            ->keyBy('product_id');
        $usage = $this->usage->forClient($regionDay->business_day_id, $client->id, $order->id);
        $counters = $this->ledger->lock($regionDay->id, $productIds);

        $checked = [];
        $violations = [];
        foreach ($decided as $line) {
            if ($line['qty'] === 0) {
                continue;
            }
            $productId = $line['product_id'];
            /** @var RegionQuota|null $counter */
            $counter = $counters->get($productId);
            $quota = $counter === null ? 0 : $counter->quantity;
            $validated = $counter === null ? 0 : $counter->validated_qty;
            $limit = $limits->get($productId)?->max_qty;
            $clientValidated = $usage[$productId]['validated'] ?? 0;
            $checked[$productId] = [
                'line' => $line['index'],
                'product_id' => $productId,
                'sku' => $line['line']->product?->sku,
                'requested' => $line['qty'],
                'quota' => $quota,
                'validated' => $validated,
                'limit' => $limit,
                'client_validated' => $clientValidated,
                'max_allowed' => DecisionRights::maxValidatable($line['requested'], $quota, $validated, $limit, $clientValidated),
            ];
            if ($line['qty'] > $checked[$productId]['max_allowed']) {
                $violations[] = [...$checked[$productId], 'cause' => $line['qty'] > $quota - $validated ? 'REGION_QUOTA' : 'CLIENT_LIMIT'];
            }
        }
        if ($violations !== []) {
            $region = array_filter($violations, fn (array $violation) => $violation['cause'] === 'REGION_QUOTA');
            [$code, $key, $count] = $region !== []
                ? [ErrorCode::RegionQuotaExceeded, 'orders.decision.region_exceeded', count($region)]
                : [ErrorCode::ClientLimitExceeded, 'orders.decision.limit_exceeded', count($violations)];
            throw new ApiException($code, trans_choice($key, $count, ['count' => $count]), ['violations' => $violations]);
        }

        return $checked;
    }

    /** Client de la commande, première instruction de la transaction (I-67). */
    private function lockClient(Order $order): Client
    {
        return $this->locks->client($order->client_id) ?? throw new LogicException("Client introuvable : {$order->client_id}.");
    }

    private function lockOrder(int $orderId): Order
    {
        return $this->locks->order($orderId) ?? throw new ApiException(ErrorCode::NotFound, __('orders.not_found'));
    }

    /** Décision déjà appliquée par le même auteur, version suivante (I-92). */
    private function isReplay(Order $order, OrderStatus $to, int $version, User $by): bool
    {
        return $order->status === $to && $order->version === $version + 1 && $order->decided_by === $by->id;
    }
}
