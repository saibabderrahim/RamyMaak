<?php

namespace App\Domain\Imports;

use App\Models\BusinessDay;
use App\Models\Import;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use LogicException;

/**
 * Modèle de fichier d'un type d'import (Annexes B.1, B.3 à B.5) : colonnes,
 * contrôle d'une ligne, application et données du gabarit. Les contrôles
 * sont rejoués à l'application avec les données du moment (§8.1, étape 5).
 * Un import de quotas porte en plus sur une journée et un mode (§8.1).
 */
abstract class ImportDefinition
{
    /** Codes ERP (clients, produits), comme dans l'API. */
    private const CODE_PATTERN = '/^[A-Z0-9_-]{1,30}$/';

    public function __construct(protected readonly User $actor) {}

    /**
     * @param  BusinessDay|null  $day  journée visée (quotas, obligatoire)
     * @param  ImportMode|null  $mode  mode de l'import (quotas ; Compléter par défaut, pour le gabarit)
     */
    public static function for(ImportType $type, User $actor, ?BusinessDay $day = null, ?ImportMode $mode = null): self
    {
        $class = match ($type) {
            ImportType::Clients => ClientsDefinition::class,
            ImportType::Products => ProductsDefinition::class,
            ImportType::Prices => PricesDefinition::class,
            ImportType::Quotas => QuotasDefinition::class,
        };

        $parameters = ['actor' => $actor];
        if ($type->isDaily()) {
            if ($day === null) {
                throw new LogicException("Un import {$type->value} porte sur une journée.");
            }
            $parameters += ['day' => $day, 'mode' => $mode ?? ImportMode::Complete];
        }

        return app()->make($class, $parameters);
    }

    /** Modèle d'un import déposé : son type, et pour les quotas sa journée et son mode. */
    public static function forImport(Import $import, User $actor): self
    {
        return self::for($import->type, $actor, $import->businessDay, $import->mode);
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

    /**
     * En-têtes du gabarit, dans l'ordre : colonnes de l'annexe B (marquées
     * obligatoires ou non) puis colonnes d'information.
     *
     * @return list<array{header: string, required: bool, information: bool}>
     */
    public function templateColumns(): array
    {
        $required = array_flip($this->requiredColumns());

        return [
            ...array_map(fn (string $column) => ['header' => $column, 'required' => isset($required[$column]), 'information' => false], $this->columns()),
            ...array_map(fn (string $column) => ['header' => self::informationHeader($column), 'required' => false, 'information' => true], $this->informationColumns()),
        ];
    }

    /** Nom de la feuille du gabarit ; null : libellé du type. */
    public function sheetTitle(): ?string
    {
        return null;
    }

    /**
     * Contexte de l'import contrôlé avant la lecture du fichier (journée
     * ouverte pour les quotas) : une erreur de niveau fichier l'arrête.
     */
    public function checkContext(AnalysisResult $result): void {}

    /**
     * Colonnes reconnues de l'en-tête (§8.3) : colonnes en double et
     * colonnes obligatoires absentes sont des erreurs de niveau fichier ; les
     * colonnes inconnues sont confiées à unknownHeader().
     *
     * @param  array<int, string>  $header  index de colonne (1 = A) → en-tête normalisé
     * @return array<string, int> colonne → index de colonne
     */
    public function resolveHeader(array $header, AnalysisResult $result): array
    {
        $columns = [];
        $duplicates = [];

        foreach ($header as $index => $name) {
            $column = $this->headerColumn($name);
            if ($column === null) {
                $this->unknownHeader($name, $result);

                continue;
            }

            if (isset($columns[$column])) {
                $duplicates[$column] = true;

                continue;
            }

            $columns[$column] = $index;
        }

        foreach (array_keys($duplicates) as $name) {
            $result->fileError($name, __('imports.errors.header', ['column' => $name, 'message' => __('imports.errors.duplicate_column')]));
        }

        foreach ($this->requiredColumns() as $name) {
            if (! isset($columns[$name])) {
                $result->fileError($name, __('imports.errors.header', ['column' => $name, 'message' => __('imports.errors.missing_column')]));
            }
        }

        return $columns;
    }

    /** Colonne désignée par un en-tête normalisé ; null si elle est inconnue. */
    protected function headerColumn(string $name): ?string
    {
        return in_array($name, $this->columns(), true) ? $name : null;
    }

    /** En-tête inconnu : ignoré (§8.3, colonnes d'information comprises). */
    protected function unknownHeader(string $name, AnalysisResult $result): void {}

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

    /**
     * Fin de l'analyse, toutes les lignes contrôlées : changements qui ne
     * viennent d'aucune ligne (produits absents d'un import de quotas en mode
     * Remplacer) et erreurs de niveau fichier.
     */
    public function finish(AnalysisResult $result): void {}

    /** Enregistre une ligne créée ou modifiée (dans la transaction de l'import). */
    abstract public function apply(RowPlan $plan): void;

    /**
     * Enregistre toutes les lignes à créer ou modifier, dans la transaction
     * de l'import.
     *
     * @param  list<RowPlan>  $plans
     */
    public function applyAll(array $plans): void
    {
        foreach ($plans as $plan) {
            $this->apply($plan);
        }
    }

    /**
     * Propriétés ajoutées à l'entrée imports / applied du journal.
     *
     * @return array<string, mixed>
     */
    public function auditProperties(): array
    {
        return [];
    }

    /**
     * Lignes du gabarit pré-rempli, alignées sur templateColumns() : texte,
     * nombre, date ou null.
     *
     * @return iterable<list<string|int|float|CarbonInterface|null>>
     */
    abstract public function templateRows(): iterable;

    /** En-tête d'une colonne d'information du gabarit, ex. « designation (information) ». */
    protected static function informationHeader(string $column): string
    {
        return __('imports.template.information', ['column' => $column]);
    }

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

    /**
     * @param  string|null  $invalid  message d'une valeur qui n'est pas un entier ≥ $min
     *                                (par défaut : entier positif, ou positif ou nul)
     */
    protected function integer(RowContext $row, string $column, int $min, int $max, ?string $invalid = null): ?int
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
            $row->fail($column, __($invalid ?? ($min > 0 ? 'imports.errors.positive_integer' : 'imports.errors.natural_integer'), ['value' => $cell->display()]));

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
