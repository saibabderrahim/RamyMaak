<?php

namespace App\Jobs;

use App\Domain\Imports\ImportProcessor;
use App\Domain\Imports\ImportStatus;
use App\Models\Import;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Analyse d'un fichier importé en tâche de fond (§8.1 étape 2, §9.7) :
 * progression, rapport, aperçu et fichier d'erreurs annoté. Rejouable sans
 * double effet : un import déjà analysé n'est pas repris.
 */
class AnalyzeImport implements ShouldQueue
{
    use Queueable;

    /** Une seule tentative : un échec technique laisse l'import en FAILED. */
    public int $tries = 1;

    /**
     * Secondes, sous le retry_after de la file (90 s) pour qu'une tâche lente
     * ne soit jamais relancée en parallèle ; 2 000 clients s'analysent en
     * quelques secondes (§13.1).
     */
    public int $timeout = 80;

    public bool $failOnTimeout = true;

    public function __construct(public Import $import)
    {
        $this->afterCommit();
    }

    public function handle(ImportProcessor $processor): void
    {
        $processor->analyze($this->import);
    }

    /** Tâche interrompue (délai dépassé, worker arrêté) : l'import ne reste pas « en cours ». */
    public function failed(?Throwable $exception): void
    {
        $import = $this->import->fresh();
        if ($import !== null && in_array($import->status, [ImportStatus::Pending, ImportStatus::Analyzing], true)) {
            app(ImportProcessor::class)->markFailed($import);
        }
    }
}
