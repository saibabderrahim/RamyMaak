<?php

namespace App\Domain\Imports;

use App\Models\Import;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Fichier d'erreurs annoté (§8.1, étape 3) : copie du fichier d'origine dont
 * la première feuille reçoit une colonne « erreurs » (messages de la ligne)
 * et des cellules fautives surlignées. Stocké à côté du fichier d'origine,
 * hors de la partie publique.
 */
final class ErrorReport
{
    private const FAULT_FILL = 'FFFFC7CE';

    private const FAULT_FONT = 'FF9C0006';

    /** Chemin du rapport sur le disque « local ». */
    public static function pathFor(Import $import): string
    {
        return dirname($import->file_path).'/'.pathinfo($import->file_path, PATHINFO_FILENAME).'-erreurs.xlsx';
    }

    public function write(Import $import, AnalysisResult $result): string
    {
        $disk = Storage::disk('local');

        $reader = new XlsxReader;
        $spreadsheet = $reader->load($disk->path($import->file_path));
        $this->neutralize($spreadsheet);
        $sheet = $spreadsheet->getSheet(0);

        $reportColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn()) + 1;

        /** @var array<int, list<string>> $messages ligne → messages (1 : en-tête) */
        $messages = [];
        foreach ($result->errors as $error) {
            $line = $error['row'] ?? 1;
            $messages[$line][] = $error['message'];

            $column = $error['column'] === null ? null : ($result->columns[$error['column']] ?? null);
            if ($column !== null) {
                $sheet->getStyle([$column, $line])->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB(self::FAULT_FILL);
            }
        }

        $header = [__('imports.report.column'), ...($messages[1] ?? [])];
        unset($messages[1]);
        $this->annotate($sheet, $reportColumn, 1, implode("\n", $header));
        $sheet->getStyle([$reportColumn, 1])->getFont()->setBold(true);

        foreach ($messages as $line => $lineMessages) {
            $this->annotate($sheet, $reportColumn, $line, implode("\n", array_unique($lineMessages)));
        }

        $sheet->getColumnDimensionByColumn($reportColumn)->setWidth(80);

        $path = self::pathFor($import);
        $temporary = tempnam(sys_get_temp_dir(), 'import-report-');

        try {
            $writer = new XlsxWriter($spreadsheet);
            // Aucune formule recalculée (il n'en reste d'ailleurs aucune).
            $writer->setPreCalculateFormulas(false);
            $writer->save($temporary);
            $spreadsheet->disconnectWorksheets();

            $stream = fopen($temporary, 'rb');
            $disk->put($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        } finally {
            @unlink($temporary);
        }

        return $path;
    }

    /**
     * Le rapport peut être ouvert par un autre utilisateur que l'auteur du
     * fichier (Admin) : aucune formule ni aucun lien du fichier déposé n'y reste
     * actif (§8.4, §12). Une formule devient sa dernière valeur calculée, ou
     * son texte quand le fichier n'en contient pas ; jamais recalculée ici.
     */
    private function neutralize(Spreadsheet $spreadsheet): void
    {
        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            foreach (array_keys($worksheet->getHyperlinkCollection()) as $coordinate) {
                $worksheet->setHyperlink($coordinate);
            }

            foreach ($worksheet->getCoordinates() as $coordinate) {
                $cell = $worksheet->getCell($coordinate);
                if (! $cell->isFormula()) {
                    continue;
                }

                $value = $cell->getOldCalculatedValue();
                if (is_int($value) || is_float($value)) {
                    $cell->setValueExplicit($value, DataType::TYPE_NUMERIC);

                    continue;
                }

                $text = is_scalar($value) ? (string) $value : (string) $cell->getValue();
                $cell->setValueExplicit($text, DataType::TYPE_STRING);
                if ($text !== '' && in_array($text[0], ['=', '+', '-', '@'], true)) {
                    $cell->getStyle()->setQuotePrefix(true);
                }
            }
        }
    }

    private function annotate(Worksheet $sheet, int $column, int $line, string $text): void
    {
        // Texte explicite : un message n'est jamais lu comme une formule (§8.4).
        $sheet->getCell([$column, $line])->setValueExplicit($text, DataType::TYPE_STRING);
        $style = $sheet->getStyle([$column, $line]);
        $style->getAlignment()->setWrapText(true);
        $style->getFont()->getColor()->setARGB(self::FAULT_FONT);
    }
}
