<?php

namespace App\Domain\Documents;

use App\Models\BusinessDay;
use App\Support\Csv;

/**
 * Export CSV pour Excel français (I-85) : séparateur « ; », UTF-8 avec BOM,
 * virgule décimale, dates JJ/MM/AAAA. Mêmes colonnes que l'XLSX. Les cellules
 * qui commenceraient une formule sont neutralisées (§8.4).
 */
final class CsvLayout implements ExportLayout
{
    public function contentType(): string
    {
        return 'text/csv; charset=UTF-8';
    }

    public function filename(BusinessDay $day): string
    {
        return 'commandes-'.$day->dayKey().'.csv';
    }

    public function write(iterable $lines, string $target = 'php://output'): void
    {
        $out = fopen($target, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Sortie de l\'export inaccessible.');
        }

        try {
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map(Csv::sanitize(...), StandardColumns::headers()), ';', '"', '');
            foreach ($lines as $line) {
                fputcsv($out, array_map(self::cell(...), StandardColumns::values($line)), ';', '"', '');
            }
            // Montants indicatifs (RG-P05), après une ligne vide : le tableau reste filtrable dans Excel.
            fwrite($out, "\n");
            fputcsv($out, [Csv::sanitize(Formats::amountNotice())], ';', '"', '');
        } finally {
            fclose($out);
        }
    }

    /** Nombre à virgule décimale, texte neutralisé. */
    private static function cell(string|int|float|null $value): string
    {
        return match (true) {
            is_float($value) => number_format($value, 2, ',', ''),
            is_int($value) => (string) $value,
            default => Csv::sanitize($value),
        };
    }
}
