<?php

namespace App\Domain\Orders;

use App\Support\BusinessClock;
use Carbon\CarbonImmutable;

/**
 * Heure limite de validation applicable à une région (RG-V08, §4.3) :
 * prolongation de la région comprise (I-71), caractère bloquant (paramètre
 * `cutoffs.validation_blocking`), dépassée ou non à l'instant évalué.
 */
final readonly class ValidationState
{
    public function __construct(
        public CarbonImmutable $cutoffAt,
        public bool $extended,
        public bool $blocking,
        public bool $passed,
    ) {}

    /** La validation est refusée (CUTOFF_PASSED) : heure bloquante et atteinte. */
    public function blocks(): bool
    {
        return $this->blocking && $this->passed;
    }

    /** Heure limite « HH:MM » dans le fuseau métier. */
    public function cutoff(): string
    {
        return BusinessClock::toLocal($this->cutoffAt)->format('H:i');
    }

    /**
     * Forme des réponses de l'API (`validation`, contrat du lot 6, §3).
     *
     * @return array{cutoff: string, cutoff_at: string, extended: bool, blocking: bool, passed: bool}
     */
    public function toArray(): array
    {
        return [
            'cutoff' => $this->cutoff(),
            'cutoff_at' => $this->cutoffAt->toIso8601String(),
            'extended' => $this->extended,
            'blocking' => $this->blocking,
            'passed' => $this->passed,
        ];
    }
}
