<?php

namespace App\Domain\Documents;

use App\Domain\Orders\OrderStatus;
use Carbon\CarbonImmutable;

/**
 * Bon de préparation d'une journée (RG-A04, EF-ADV-05) : en-tête (journée,
 * livraison, statuts inclus, heure de génération), groupes et total
 * général. Une journée sans commande donne un document vide (`isEmpty`),
 * imprimé « Aucune commande ».
 */
final readonly class PickingReport
{
    /**
     * @param  list<OrderStatus>  $statuses  statuts inclus
     * @param  list<PickingGroup>  $groups
     */
    public function __construct(
        public CarbonImmutable $day,
        public ?CarbonImmutable $deliveryDate,
        public PickingBy $by,
        public array $statuses,
        public CarbonImmutable $generatedAt,
        public array $groups,
        public PickingTotals $totals,
    ) {}

    public function isEmpty(): bool
    {
        return $this->groups === [];
    }

    /** Statuts inclus, en toutes lettres : « Validée, En préparation, Préparée ». */
    public function statusLabels(): string
    {
        return implode(', ', array_map(fn (OrderStatus $status) => $status->label(), $this->statuses));
    }
}
