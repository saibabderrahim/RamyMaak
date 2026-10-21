<?php

namespace App\Domain\Limits;

/**
 * Résultat de l'envoi d'une répartition (RG-B06, RG-B07) : version, nombre
 * de bornes modifiées, clients notifiés et produits en surallocation.
 */
final class DistributionResult
{
    /**
     * @param  list<int>  $notifiedClientIds
     * @param  list<array{product_id: int, sku: string, quota: int, sum_limits: int}>  $overallocated
     */
    public function __construct(
        public readonly int $version,
        public readonly bool $first,
        public readonly int $changes,
        public readonly array $notifiedClientIds,
        public readonly array $overallocated,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'first' => $this->first,
            'changes' => $this->changes,
            'notified_clients' => count($this->notifiedClientIds),
            'overallocated' => $this->overallocated,
        ];
    }
}
