<?php

namespace App\Domain\Orders;

use App\Models\BusinessDay;
use App\Models\RegionDay;
use Illuminate\Support\Carbon;

/**
 * Prise de commande d'un client pour une journée : journée et journée
 * régionale (jamais créées à la lecture), statut, heure limite de commande
 * applicable à la région (prolongation comprise, RG-A05).
 */
final readonly class OrderingState
{
    public function __construct(
        public string $day,
        public ?BusinessDay $businessDay,
        public ?RegionDay $regionDay,
        public int $regionId,
        public ClientDayStatus $status,
        public ?Carbon $cutoffAt,
        public bool $extended,
    ) {}

    public function isOpen(): bool
    {
        return $this->status === ClientDayStatus::OrderingOpen;
    }
}
