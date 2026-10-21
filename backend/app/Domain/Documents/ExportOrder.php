<?php

namespace App\Domain\Documents;

use App\Domain\Orders\OrderStatus;
use Carbon\CarbonImmutable;

/**
 * En-tête d'une commande de l'export (OrderExport) : lu une fois et partagé
 * par toutes ses lignes. La région et le dépôt sont ceux d'aujourd'hui pour
 * le dépôt (I-84), ceux figés à l'envoi pour la région (RG-S04). Instants en
 * UTC, mis en forme par la présentation.
 */
final readonly class ExportOrder
{
    public function __construct(
        public CarbonImmutable $day,
        public ?CarbonImmutable $deliveryDate,
        public string $number,
        public OrderStatus $status,
        public bool $adjusted,
        public string $clientCode,
        public string $clientName,
        public string $regionCode,
        public string $regionName,
        public ?string $depotCode,
        public ?string $depotName,
        public ?CarbonImmutable $submittedAt,
        public ?CarbonImmutable $decidedAt,
        public ?string $decidedBy,
        public ?CarbonImmutable $shippedAt,
        public ?string $deliveryNoteRef,
    ) {}
}
