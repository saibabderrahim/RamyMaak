<?php

namespace App\Domain\Imports;

/**
 * Une ligne de données en cours de contrôle et ses erreurs, au format du
 * §8.1 : « Ligne 14, colonne code_region : code région inconnu : EST2 ».
 */
final class RowContext
{
    /** @var list<array{row: int, column: string, message: string}> */
    private array $errors = [];

    /** @var array<string, true> */
    private array $failed = [];

    /**
     * @param  array<string, SheetCell>  $cells  cellules non vides, par colonne
     */
    public function __construct(public readonly int $line, private readonly array $cells)
    {
        foreach ($cells as $column => $cell) {
            if ($cell->error !== null) {
                $this->fail($column, __('imports.errors.formula', ['value' => $cell->error]));
            }
        }
    }

    public function cell(string $column): SheetCell
    {
        return $this->cells[$column] ?? SheetCell::empty();
    }

    /** Cellule renseignée et lisible (une erreur Excel est déjà signalée). */
    public function filled(string $column): bool
    {
        return isset($this->cells[$column]) && ! isset($this->failed[$column]);
    }

    /** Cellule vide : laissée inchangée en mise à jour (§8.3). */
    public function blank(string $column): bool
    {
        return ! isset($this->cells[$column]);
    }

    public function fail(string $column, string $message): void
    {
        $this->failed[$column] = true;
        $this->errors[] = [
            'row' => $this->line,
            'column' => $column,
            'message' => __('imports.errors.cell', ['line' => $this->line, 'column' => $column, 'message' => $message]),
        ];
    }

    public function failed(string $column): bool
    {
        return isset($this->failed[$column]);
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return list<array{row: int, column: string, message: string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
