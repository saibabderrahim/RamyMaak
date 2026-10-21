<?php

namespace App\Domain\Imports;

use App\Http\Problems\ApiException;

/**
 * Résultat du contrôle d'un fichier : compteurs complets, erreurs, résumé
 * et plan d'application des lignes valides.
 */
final class AnalysisResult
{
    public int $rowsTotal = 0;

    public int $rowsOk = 0;

    public int $rowsError = 0;

    /** @var list<array{row: ?int, column: ?string, message: string}> */
    public array $errors = [];

    /** @var array{create: int, update: int, unchanged: int}|null null si les lignes n'ont pas pu être lues */
    public ?array $summary = null;

    /** @var list<RowPlan> lignes valides à créer ou modifier, dans l'ordre du fichier */
    public array $plans = [];

    /** @var array<string, int> colonne reconnue → index de colonne (1 = A) */
    public array $columns = [];

    /** Classeur lu : un fichier d'erreurs annoté peut en être tiré. */
    public bool $readable = false;

    /**
     * Erreur d'état (journée clôturée) : l'application répond par ce
     * problème plutôt que par IMPORT_HAS_ERRORS.
     */
    public ?ApiException $conflict = null;

    public function __construct(public readonly ImportDefinition $definition) {}

    public function fileError(?string $column, string $message): void
    {
        $this->errors[] = ['row' => null, 'column' => $column, 'message' => $message];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Aperçu des changements : les premières lignes créées ou modifiées.
     *
     * @return list<array{row: ?int, key: string, action: string, changes: list<array{field: string, old: mixed, new: mixed}>}>
     */
    public function preview(int $limit): array
    {
        return array_map(fn (RowPlan $plan) => $plan->preview(), array_slice($this->plans, 0, $limit));
    }
}
