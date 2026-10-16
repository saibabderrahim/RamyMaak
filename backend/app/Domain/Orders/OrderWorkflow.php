<?php

namespace App\Domain\Orders;

use App\Http\Problems\ApiException;
use App\Http\Problems\ErrorCode;

/**
 * Transitions de la commande (§6.3) : toute transition non listée est
 * refusée (INVALID_TRANSITION). Le lot 5 exerce l'envoi, la modification,
 * l'annulation par le client et l'expiration ; les lignes du régional et de
 * l'ADV sont celles du lot 6.
 */
final class OrderWorkflow
{
    /** @var array<string, array<string, list<OrderActor>>> de → vers → acteurs */
    private const TRANSITIONS = [
        'SUBMITTED' => [
            'SUBMITTED' => [OrderActor::Client],   // modification (RG-C05)
            'CANCELLED' => [OrderActor::Client],   // annulation avant décision
            'VALIDATED' => [OrderActor::Regional], // validation, avec ou sans ajustement (lot 6)
            'REJECTED' => [OrderActor::Regional],  // rejet motivé (lot 6)
            'EXPIRED' => [OrderActor::System],     // clôture de la journée (§4.4)
        ],
        'VALIDATED' => [
            'IN_PREPARATION' => [OrderActor::Adv],
            'CANCELLED' => [OrderActor::Adv],      // annulation exceptionnelle (RG-V07)
        ],
        'IN_PREPARATION' => [
            'PREPARED' => [OrderActor::Adv],
            'CANCELLED' => [OrderActor::Adv],
        ],
        'PREPARED' => [
            'SHIPPED' => [OrderActor::Adv],
        ],
        'SHIPPED' => [
            'DELIVERED' => [OrderActor::Adv],      // V2
        ],
    ];

    public function can(OrderStatus $from, OrderStatus $to, OrderActor $actor): bool
    {
        return in_array($actor, self::TRANSITIONS[$from->value][$to->value] ?? [], true);
    }

    /** @throws ApiException INVALID_TRANSITION */
    public function assertCan(OrderStatus $from, OrderStatus $to, OrderActor $actor): void
    {
        if (! $this->can($from, $to, $actor)) {
            throw new ApiException(ErrorCode::InvalidTransition, __('orders.invalid_transition', [
                'from' => $from->label(),
                'to' => $to->label(),
            ]));
        }
    }

    /**
     * Statuts atteignables depuis un statut par un acteur.
     *
     * @return list<OrderStatus>
     */
    public function targets(OrderStatus $from, OrderActor $actor): array
    {
        $targets = [];
        foreach (self::TRANSITIONS[$from->value] ?? [] as $to => $actors) {
            if (in_array($actor, $actors, true)) {
                $targets[] = OrderStatus::from($to);
            }
        }

        return $targets;
    }
}
