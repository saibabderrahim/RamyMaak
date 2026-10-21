<?php

namespace App\Domain\Orders;

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Days\RegionDayStatus;
use App\Domain\Referentials\ClientStatus;
use App\Http\Problems\ErrorCode;
use App\Models\ClientLimit;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\RegionDay;
use App\Models\RegionQuota;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Droits de décision et contexte de quotas d'une commande, pour les vues du
 * régional (EF-REG-05 ; contrat du lot 6, §3). Lecture sans verrou : ce sont
 * des indications, OrderDecisions refait tous les contrôles sous verrou avec
 * la même formule (maxValidatable).
 *
 * - `can_validate` : commande Envoyée, droit `order.validate`, journée et
 *   région non clôturées, client actif, heure limite non bloquante ou non
 *   atteinte (RG-V08) ;
 * - `can_reject` : commande Envoyée, droit `order.validate`, journée non
 *   clôturée (ni l'heure limite ni le statut du client ne bloquent, I-70) ;
 * - `blocked_reason` : DAY_NOT_OPEN, CUTOFF_PASSED puis CLIENT_BLOCKED, dans
 *   l'ordre des contrôles de la validation (I-68).
 */
final class DecisionRights
{
    public function __construct(
        private readonly ValidationWindow $window,
        private readonly OrderUsage $usage,
    ) {}

    /**
     * Quantité validable d'une ligne (RG-V02 à RG-V04, I-91) :
     * max(0, min(demandé, R, B − V(c,p))), R = Q − V(r,p) ; sans borne, pas
     * de plafond client ; borne 0 → 0.
     */
    public static function maxValidatable(int $requested, int $quota, int $regionValidated, ?int $limit, int $clientValidated): int
    {
        $max = min($requested, $quota - $regionValidated);
        if ($limit !== null) {
            $max = min($max, $limit - $clientValidated);
        }

        return max(0, $max);
    }

    /**
     * Journée régionale de la commande (région figée à l'envoi), null si elle
     * n'existe pas.
     */
    public function regionDayOf(Order $order): ?RegionDay
    {
        return RegionDay::query()
            ->where('business_day_id', $order->business_day_id)
            ->where('region_id', $order->region_id)
            ->first();
    }

    /**
     * Commande lue avec sa journée et son client (chargés au besoin) ;
     * `$regionDay` : journée régionale déjà lue, sinon relue ici.
     */
    public function evaluate(User $user, Order $order, ?RegionDay $regionDay = null, ?CarbonInterface $now = null): DecisionState
    {
        $order->loadMissing(['businessDay', 'client:id,status']);
        // Journée chargée en partie (OrderPresenter::RELATIONS : id et jour) : les heures limites manquent, on la relit.
        if (! array_key_exists('validation_cutoff_at', $order->businessDay->getAttributes())) {
            $order->setRelation('businessDay', $order->businessDay()->getResults());
        }
        $day = $order->businessDay;
        $regionDay ??= $this->regionDayOf($order);
        $validation = $this->window->state($day, $regionDay, now: $now);

        $allowed = $order->status === OrderStatus::Submitted
            && $user->hasAppRole(Role::Regional)
            && $user->can(Permission::OrderValidate->value);
        $dayOpen = $day->isOpen();
        $blocked = match (true) {
            ! $allowed => null,
            ! $dayOpen, $regionDay === null, $regionDay?->status === RegionDayStatus::Closed => ErrorCode::DayNotOpen,
            $validation->blocks() => ErrorCode::CutoffPassed,
            $order->client->status !== ClientStatus::Active => ErrorCode::ClientBlocked,
            default => null,
        };

        return new DecisionState($validation, $allowed && $blocked === null, $allowed && $dayOpen, $blocked);
    }

    /**
     * Contexte publié de chaque ligne (EF-REG-05), en trois ou quatre
     * requêtes quel que soit le nombre de lignes : borne publiée B(c,p) (null
     * sans borne, brouillons ignorés), V(c,p) du client ce jour-là, Q(r,p),
     * V(r,p), R(r,p) et quantité validable. Pour une commande déjà validée,
     * la quantité validable ne compte pas ses propres quantités.
     *
     * @return array<int, array{limit: ?int, client_validated: int, region_quota: int, region_validated: int, region_remaining: int, max_validatable: int}> produit → contexte
     */
    public function context(Order $order, ?RegionDay $regionDay = null): array
    {
        $order->loadMissing('lines');
        $productIds = $order->lines->pluck('product_id')->all();
        $regionDay ??= $this->regionDayOf($order);
        $limits = $regionDay === null ? collect() : ClientLimit::query()
            ->where('region_day_id', $regionDay->id)
            ->where('client_id', $order->client_id)
            ->whereIn('product_id', $productIds)
            ->pluck('max_qty', 'product_id');
        $quotas = $regionDay === null ? collect() : RegionQuota::query()
            ->where('region_day_id', $regionDay->id)
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'quantity', 'validated_qty'])
            ->keyBy('product_id');
        $usage = $this->usage->forClient($order->business_day_id, $order->client_id);
        $counted = in_array($order->status, OrderStatus::validated(), true);

        $context = [];
        /** @var OrderLine $line */
        foreach ($order->lines as $line) {
            $productId = $line->product_id;
            $limit = $limits->get($productId);
            $limit = $limit === null ? null : (int) $limit;
            /** @var RegionQuota|null $quota */
            $quota = $quotas->get($productId);
            $quantity = $quota === null ? 0 : $quota->quantity;
            $validated = $quota === null ? 0 : $quota->validated_qty;
            $clientValidated = $usage[$productId]['validated'] ?? 0;
            $own = $counted ? (int) $line->validated_qty : 0;
            $context[$productId] = [
                'limit' => $limit,
                'client_validated' => $clientValidated,
                'region_quota' => $quantity,
                'region_validated' => $validated,
                'region_remaining' => $quantity - $validated,
                'max_validatable' => self::maxValidatable($line->requested_qty, $quantity, $validated - $own, $limit, $clientValidated - $own),
            ];
        }

        return $context;
    }
}
