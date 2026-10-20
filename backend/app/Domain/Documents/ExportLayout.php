<?php

namespace App\Domain\Documents;

use App\Models\BusinessDay;

/**
 * Présentation d'un export des commandes de la journée (XLSX, CSV pour
 * Excel français, format ERP) : nom du fichier, type de contenu et écriture
 * des lignes, toujours en flux.
 */
interface ExportLayout
{
    public function contentType(): string;

    public function filename(BusinessDay $day): string;

    /**
     * Écrit l'export, ligne après ligne, sur `$target` (la sortie par défaut).
     *
     * @param  iterable<ExportLine>  $lines
     */
    public function write(iterable $lines, string $target = 'php://output'): void;
}
