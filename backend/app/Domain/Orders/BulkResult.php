<?php

namespace App\Domain\Orders;

/**
 * Résultat d'un changement de statut en masse (RG-A02, I-78) : commandes
 * passées au statut (ou déjà passées par un renvoi) et refus par commande,
 * dans l'ordre de la demande. Un refus n'annule pas les autres. Codes de
 * refus : NOT_FOUND (inconnue ou hors périmètre, `number` et `version`
 * null), INVALID_TRANSITION, VERSION_CONFLICT (`version` : version actuelle).
 */
final readonly class BulkResult
{
    /**
     * @param  list<array{id: string, number: ?string, status: string, version: int}>  $applied
     * @param  list<array{id: string, number: ?string, code: string, detail: string, version: ?int}>  $refused
     */
    public function __construct(
        public array $applied,
        public array $refused,
    ) {}

    /**
     * Forme de la réponse (contrat du lot 6, §5).
     *
     * @return array{applied: list<array{id: string, number: ?string, status: string, version: int}>, refused: list<array{id: string, number: ?string, code: string, detail: string, version: ?int}>}
     */
    public function toArray(): array
    {
        return ['applied' => $this->applied, 'refused' => $this->refused];
    }
}
