<?php

namespace App\Domain\Imports;

use App\Models\Import;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Storage;

/**
 * Contrôle d'un fichier importé (§8.1, §8.3), sans rien écrire : contexte
 * (journée ouverte pour les quotas), en-tête (colonnes obligatoires, doublons
 * de colonnes), lignes une à une, doublons de clé dans le fichier. Rejoué à
 * l'application avec les données du moment.
 */
final class ImportAnalyzer
{
    /**
     * Lignes de données par fichier : 2,5 fois les volumes attendus (§13.1 :
     * 2 000 clients, 200 produits), dans la mémoire allouée à PHP (256 Mo).
     */
    public const MAX_ROWS = 5000;

    /** Fréquence de mise à jour de la progression (lignes). */
    public const PROGRESS_EVERY = 100;

    /**
     * @param  (Closure(int $total, int $processed): void)|null  $progress
     */
    public function run(Import $import, User $actor, ?Closure $progress = null): AnalysisResult
    {
        $definition = ImportDefinition::forImport($import, $actor);
        $result = new AnalysisResult($definition);

        $definition->checkContext($result);
        if ($result->hasErrors()) {
            return $result;
        }

        try {
            // Seules les colonnes de l'annexe B sont chargées : les autres sont ignorées.
            $columns = array_map(ImportSheet::normalizeHeader(...), $definition->columns());
            $sheet = ImportSheet::open(Storage::disk('local')->path($import->file_path), self::MAX_ROWS, $columns);
        } catch (UnreadableSheet $e) {
            $result->fileError(null, $e->getMessage());

            return $result;
        }

        $result->readable = true;

        try {
            $this->analyze($sheet, $definition, $result, $progress);
        } finally {
            $sheet->release();
        }

        return $result;
    }

    /**
     * @param  (Closure(int $total, int $processed): void)|null  $progress
     */
    private function analyze(ImportSheet $sheet, ImportDefinition $definition, AnalysisResult $result, ?Closure $progress): void
    {
        $result->columns = $definition->resolveHeader($sheet->header(), $result);
        if ($result->hasErrors()) {
            return;
        }

        $rows = [];
        foreach ($sheet->rows($result->columns) as $line => $cells) {
            if (count($rows) === self::MAX_ROWS) {
                $result->fileError(null, __('imports.errors.too_many_rows', ['max' => number_format(self::MAX_ROWS, 0, ',', "\u{202F}")]));

                return;
            }
            $rows[$line] = new RowContext($line, $cells);
        }

        $result->rowsTotal = count($rows);
        if ($rows === []) {
            $result->fileError(null, __('imports.errors.empty'));

            return;
        }

        $progress?->__invoke($result->rowsTotal, 0);

        // Clés d'abord : les fiches existantes sont chargées en une fois.
        $keys = [];
        foreach ($rows as $line => $row) {
            $keys[$line] = $definition->key($row);
        }
        $definition->preload(array_values(array_unique(array_filter($keys, fn (?string $key) => $key !== null))));

        $summary = [RowPlan::CREATE => 0, RowPlan::UPDATE => 0, RowPlan::UNCHANGED => 0];
        $firstLines = [];
        $processed = 0;

        foreach ($rows as $line => $row) {
            $key = $keys[$line];
            $plan = null;

            if ($key !== null) {
                if (isset($firstLines[$key])) {
                    $row->fail($definition->keyColumn(), __('imports.errors.duplicate', [
                        'subject' => $definition->describe($key),
                        'first' => $firstLines[$key],
                    ]));
                } else {
                    $firstLines[$key] = $line;
                }

                $plan = $definition->check($row, $key);
            } else {
                $definition->checkWithoutKey($row);
            }

            if ($row->hasErrors() || $plan === null) {
                $result->rowsError++;
                array_push($result->errors, ...$row->errors());
            } else {
                $result->rowsOk++;
                $summary[$plan->action]++;
                if ($plan->changes()) {
                    $result->plans[] = $plan;
                }
            }

            if (++$processed % self::PROGRESS_EVERY === 0) {
                $progress?->__invoke($result->rowsTotal, $processed);
            }
        }

        $result->summary = $summary;
        $definition->finish($result);
    }
}
