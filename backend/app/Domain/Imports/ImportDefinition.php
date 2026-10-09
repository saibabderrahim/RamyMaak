<?php

namespace App\Domain\Imports;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Modèle de fichier d'un type d'import (Annexes B.3 à B.5) : colonnes,
 * contrôle d'une ligne, application et données du gabarit. Les contrôles
 * sont rejoués à l'application avec les données du moment (§8.1, étape 5).
 */
abstract class ImportDefinition
{
    /** Codes ERP (clients, produits), comme dans l'API. */
    private const CODE_PATTERN = '/^[A-Z0-9_-]{1,30}$/';

    public function __construct(protected readonly User $actor) {}

    public static function for(ImportType $type, User $actor): self
    {
        $class = match ($type) {
            ImportType::Clients => ClientsDefinition::class,
            ImportType::Products => ProductsDefinition::class,
            ImportType::Prices => PricesDefinition::class,
        };

        return app()->make($class, ['actor' => $actor]);
    }

    /**
     * Colonnes de l'annexe B, dans l'ordre du gabarit.
     *
     * @return list<string>
     */
    abstract public function columns(): array;

    /**
     * Colonnes obligatoires dans l'en-tête (et à la création).
     *
     * @return list<string>
     */
    abstract public function requiredColumns(): array;

    /**
     * Colonnes d'information ajoutées au gabarit après celles de l'annexe B,
     * ignorées à l'import (comme « designation » de l'annexe B.1).
     *
     * @return list<string>
     */
    public function informationColumns(): array
    {
        return [];
    }

    /** Colonne qui porte la clé, citée par l'erreur de doublon. */
    abstract public function keyColumn(): string;

    /** Clé normalisée de la ligne ; null (erreur signalée) si elle manque ou est invalide. */
    abstract public function key(RowContext $row): ?string;

    /** Clé affichée dans l'aperçu, ex. « C0012 ». */
    abstract public function label(string $key): string;

    /** Objet de la ligne cité par l'erreur de doublon, ex. « client C0012 ». */
    abstract public function describe(string $key): string;

    /**
     * Charge les données utiles au contrôle des lignes.
     *
     * @param  list<string>  $keys  clés valides du fichier
     */
    abstract public function preload(array $keys): void;

    /** Contrôle d'une ligne ; null si elle contient au moins une erreur. */
    abstract public function check(RowContext $row, string $key): ?RowPlan;

    /**
     * Ligne sans clé valide (erreur déjà signalée) : les autres cellules
     * sont contrôlées aussi, pour que le rapport cite toutes les erreurs de
     * la ligne en une fois.
     */
    abstract public function checkWithoutKey(RowContext $row): void;

    /** Enregistre une ligne créée ou modifiée (dans la transaction de l'import). */
    abstract public function apply(RowPlan $plan): void;

    /**
     * Lignes du gabarit pré-rempli, alignées sur columns() puis
     * informationColumns() : texte, nombre, date ou null.
     *
     * @return iterable<list<string|int|float|CarbonInterface|null>>
     */
    abstract public function templateRows(): iterable;

    /** Code ERP obligatoire, mis en majuscules. */
    protected function code(RowContext $row, string $column): ?string
    {
        if (! $row->filled($column)) {
            if (! $row->failed($column)) {
                $row->fail($column, __('imports.errors.required'));
            }

            return null;
        }

        $cell = $row->cell($column);
        $code = mb_strtoupper((string) $cell->text);
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            $row->fail($column, __('imports.errors.code_format', ['value' => $cell->display()]));

            return null;
        }

        return $code;
    }

    protected function text(RowContext $row, string $column, int $max): ?string
    {
        if (! $row->filled($column)) {
            return null;
        }

        $text = (string) $row->cell($column)->text;
        if (mb_strlen($text) > $max) {
            $row->fail($column, __('imports.errors.too_long', ['max' => $max]));

            return null;
        }

        return $text;
    }

    protected function integer(RowContext $row, string $column, int $min, int $max): ?int
    {
        if (! $row->filled($column)) {
            return null;
        }

        $cell = $row->cell($column);
        $value = match (true) {
            is_int($cell->value) => $cell->value,
            is_float($cell->value) && floor($cell->value) === $cell->value && abs($cell->value) < PHP_INT_MAX => (int) $cell->value,
            is_string($cell->value) && preg_match('/^\d{1,18}$/', str_replace([' ', "\u{202F}"], '', $cell->value)) === 1 => (int) str_replace([' ', "\u{202F}"], '', $cell->value),
            default => null,
        };

        if ($value === null || $value < $min) {
            $row->fail($column, __($min > 0 ? 'imports.errors.positive_integer' : 'imports.errors.natural_integer', ['value' => $cell->display()]));

            return null;
        }

        if ($value > $max) {
            $row->fail($column, __('imports.errors.integer_max', ['value' => $cell->display(), 'max' => $max]));

            return null;
        }

        return $value;
    }

    /** Nombre décimal (« 36,72 » ou 36.72) ; null si la cellule n'en est pas un. */
    protected function decimal(RowContext $row, string $column): ?float
    {
        $cell = $row->cell($column);
        if ($cell->isNumeric()) {
            return (float) $cell->value;
        }

        if (is_string($cell->value)) {
            $text = str_replace([' ', "\u{202F}", ','], ['', '', '.'], $cell->value);
            if (preg_match('/^[+-]?\d+(\.\d+)?$/', $text) === 1) {
                return (float) $text;
            }
        }

        return null;
    }

    protected function date(RowContext $row, string $column): ?CarbonImmutable
    {
        if (! $row->filled($column)) {
            if (! $row->failed($column)) {
                $row->fail($column, __('imports.errors.required'));
            }

            return null;
        }

        $cell = $row->cell($column);
        $date = $cell->date();
        if ($date === null || $date->year < 2000 || $date->year > 2100) {
            $row->fail($column, __('imports.errors.date', ['value' => $cell->display()]));

            return null;
        }

        return $date;
    }

    /**
     * Colonnes obligatoires pour créer la fiche : une cellule vide est une
     * erreur (en mise à jour, elle laisse la valeur inchangée).
     *
     * @param  list<string>  $columns
     */
    protected function requireForCreation(RowContext $row, array $columns, string $message): void
    {
        foreach ($columns as $column) {
            if ($row->blank($column)) {
                $row->fail($column, $message);
            }
        }
    }

    /** Clé de recherche d'un libellé : casse, accents et espaces multiples ignorés. */
    protected static function fold(string $text): string
    {
        return mb_strtolower(Str::ascii((string) preg_replace('/\s+/u', ' ', trim($text))));
    }
}
