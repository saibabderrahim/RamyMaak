<?php

namespace App\Domain\Quotas;

/**
 * Résultat d'une publication de quotas : régions publiées (première
 * publication ou modifications) et régions laissées de côté, avec la raison.
 */
final class PublicationResult
{
    /** Raisons de mise à l'écart d'une région. */
    public const NO_CHANGES = 'NO_CHANGES';

    public const EMPTY = 'EMPTY';

    public const CLOSED = 'CLOSED';

    /**
     * @param  list<array{region_id: int, code: string, version: int, first: bool, changes: int}>  $published
     * @param  list<array{region_id: int, code: string, reason: string}>  $skipped
     */
    public function __construct(
        public readonly array $published = [],
        public readonly array $skipped = [],
    ) {}

    /**
     * @return array{published: list<array<string, mixed>>, skipped: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return ['published' => $this->published, 'skipped' => $this->skipped];
    }
}
