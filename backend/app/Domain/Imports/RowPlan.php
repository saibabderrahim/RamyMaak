<?php

namespace App\Domain\Imports;

use Illuminate\Database\Eloquent\Model;

/**
 * Ce que l'application d'une ligne valide fera : création, mise à jour ou
 * rien, avec les valeurs avant / après de l'aperçu (§8.1, étape 3).
 */
final class RowPlan
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const UNCHANGED = 'unchanged';

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes  champ → valeurs affichées
     * @param  array<string, mixed>  $attributes  valeurs à enregistrer
     */
    public function __construct(
        public readonly int $line,
        public readonly string $key,
        public readonly string $label,
        public readonly string $action,
        public readonly array $changes = [],
        public readonly array $attributes = [],
        public readonly ?Model $record = null,
    ) {}

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     * @param  array<string, mixed>  $attributes
     */
    public static function createOrUpdate(int $line, string $key, string $label, ?Model $record, array $changes, array $attributes): self
    {
        $action = match (true) {
            $record === null => self::CREATE,
            $changes === [] => self::UNCHANGED,
            default => self::UPDATE,
        };

        return new self($line, $key, $label, $action, $changes, $attributes, $record);
    }

    public function changes(): bool
    {
        return $this->action !== self::UNCHANGED;
    }

    /**
     * Forme stockée : `changes` est une liste, car MySQL réordonne les clés
     * d'un objet JSON ; ImportResource restitue l'objet dans l'ordre du fichier.
     *
     * @return array{row: int, key: string, action: string, changes: list<array{field: string, old: mixed, new: mixed}>}
     */
    public function preview(): array
    {
        $changes = [];
        foreach ($this->changes as $field => $change) {
            $changes[] = ['field' => $field, 'old' => $change['old'], 'new' => $change['new']];
        }

        return ['row' => $this->line, 'key' => $this->label, 'action' => $this->action, 'changes' => $changes];
    }
}
