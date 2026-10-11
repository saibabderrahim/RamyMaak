<?php

namespace App\Domain\Imports;

use App\Http\Problems\ApiException;
use App\Http\Problems\ErrorCode;
use App\Models\Import;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Facades\LogBatch;
use Throwable;

/**
 * Cycle de vie d'un import (§8.1) : analyse en tâche de fond (rapport,
 * aperçu, fichier d'erreurs annoté), puis application en une seule
 * transaction, tout ou rien, après revalidation des données.
 */
final class ImportProcessor
{
    /** Erreurs conservées dans le rapport ; les compteurs restent complets. */
    public const MAX_ERRORS = 500;

    /** Lignes créées ou modifiées montrées dans l'aperçu. */
    public const MAX_PREVIEW = 200;

    public function __construct(
        private readonly ImportAnalyzer $analyzer,
        private readonly ErrorReport $reports,
    ) {}

    /**
     * Analyse du fichier (tâche AnalyzeImport) : rejouable sans double effet,
     * un import déjà analysé n'est pas repris. Un incident technique laisse
     * l'import en FAILED avec un message générique.
     */
    public function analyze(Import $import): void
    {
        $import->refresh();
        if (! in_array($import->status, [ImportStatus::Pending, ImportStatus::Analyzing], true)) {
            return;
        }

        $import->forceFill(['status' => ImportStatus::Analyzing, 'rows_processed' => 0])->save();

        try {
            $result = $this->analyzer->run($import, $import->user, function (int $total, int $processed) use ($import): void {
                Import::query()->whereKey($import->getKey())->update(['rows_total' => $total, 'rows_processed' => $processed]);
            });

            $this->record($import, $result);
        } catch (Throwable $e) {
            report($e);
            $this->markFailed($import);
        }
    }

    public function markFailed(Import $import): void
    {
        $import->forceFill([
            'status' => ImportStatus::Failed,
            'failure_message' => __('imports.failed'),
            'analyzed_at' => now(),
        ])->save();
    }

    /**
     * Application (§8.1, étape 5) : fichier revalidé avec les données du
     * moment puis appliqué dans une seule transaction. Une nouvelle erreur
     * rend l'import INVALID et rien n'est écrit. Les changements de chaque
     * fiche sont regroupés par batch_uuid avec l'entrée imports / applied.
     * Les quotas sont enregistrés en brouillon, jamais publiés (RG-Q09).
     *
     * @throws ApiException INVALID_TRANSITION, IMPORT_HAS_ERRORS, DAY_NOT_OPEN (journée clôturée depuis l'analyse)
     */
    public function apply(Import $import, User $by): Import
    {
        $conflict = null;
        $applied = DB::transaction(function () use ($import, $by, &$conflict): bool {
            /** @var Import $locked */
            $locked = Import::query()->whereKey($import->getKey())->lockForUpdate()->firstOrFail();
            $this->assertApplicable($locked);

            $result = $this->analyzer->run($locked, $by);
            if ($result->hasErrors()) {
                $this->record($locked, $result);
                $conflict = $result->conflict;

                return false;
            }

            LogBatch::withinBatch(function () use ($locked, $result, $by): void {
                $result->definition->applyAll($result->plans);

                activity('imports')
                    ->performedOn($locked)
                    ->causedBy($by)
                    ->event('applied')
                    ->withProperties([
                        'type' => $locked->type->value,
                        'file' => $locked->original_name,
                        'summary' => $result->summary,
                        ...$result->definition->auditProperties(),
                    ])
                    ->log(__('imports.audit.applied'));
            });

            $locked->forceFill([
                ...$this->counters($result),
                'status' => ImportStatus::Applied,
                'applied_at' => now(),
            ])->save();

            return true;
        });

        if (! $applied) {
            throw $conflict ?? new ApiException(ErrorCode::ImportHasErrors, __('imports.apply.new_errors'));
        }

        return $import->refresh();
    }

    private function assertApplicable(Import $import): void
    {
        match ($import->status) {
            ImportStatus::Ready => null,
            ImportStatus::Invalid => throw new ApiException(ErrorCode::ImportHasErrors, __('imports.apply.has_errors')),
            default => throw new ApiException(ErrorCode::InvalidTransition, __('imports.apply.'.strtolower($import->status->value))),
        };
    }

    private function record(Import $import, AnalysisResult $result): void
    {
        $invalid = $result->hasErrors();

        $import->forceFill([
            ...$this->counters($result),
            'status' => $invalid ? ImportStatus::Invalid : ImportStatus::Ready,
            'error_report_path' => $invalid && $result->readable ? $this->writeReport($import, $result) : null,
            'analyzed_at' => now(),
            'failure_message' => null,
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function counters(AnalysisResult $result): array
    {
        return [
            'rows_total' => $result->rowsTotal,
            'rows_processed' => $result->rowsTotal,
            'rows_ok' => $result->rowsOk,
            'rows_error' => $result->rowsError,
            // Une entrée de plus que l'affichage : la ressource sait si la liste est tronquée.
            'errors' => array_slice($result->errors, 0, self::MAX_ERRORS + 1),
            'summary' => $result->summary,
            'preview' => $result->preview(self::MAX_PREVIEW + 1),
        ];
    }

    private function writeReport(Import $import, AnalysisResult $result): ?string
    {
        try {
            return $this->reports->write($import, $result);
        } catch (Throwable $e) {
            // Le rapport reste consultable dans l'API sans le fichier annoté.
            report($e);

            return null;
        }
    }
}
