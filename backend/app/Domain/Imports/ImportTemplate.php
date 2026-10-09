<?php

namespace App\Domain\Imports;

use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonInterface;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gabarits téléchargeables (§6 du contrat, Annexes B.3 à B.5) : en-têtes de
 * l'annexe B, pré-remplis avec les données actuelles. Réimporté tel quel, un
 * gabarit ne change rien. Les textes commençant par =, +, - ou @ sont
 * neutralisés (§8.4) par le préfixe « ' » d'Excel, sans altérer la valeur.
 */
final class ImportTemplate
{
    public const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const FORMULA_PREFIXES = ['=', '+', '-', '@'];

    private const HEADER_FILL = 'FFE7E6E6';

    private const REQUIRED_FILL = 'FFFFE699';

    private const INFORMATION_FONT = 'FF7F7F7F';

    public function download(ImportType $type, User $user): StreamedResponse
    {
        $spreadsheet = $this->build($type, $user);
        $filename = __('imports.template.filename', [
            'type' => __('imports.template.names.'.$type->value),
            'date' => BusinessClock::today()->format('Ymd'),
        ]);

        return response()->streamDownload(function () use ($spreadsheet): void {
            $writer = new XlsxWriter($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, ['Content-Type' => self::CONTENT_TYPE]);
    }

    public function build(ImportType $type, User $user): Spreadsheet
    {
        $definition = ImportDefinition::for($type, $user);
        $columns = $definition->columns();
        $information = $definition->informationColumns();
        $required = array_flip($definition->requiredColumns());

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($type->label());

        $headers = [
            ...$columns,
            ...array_map(fn (string $column) => __('imports.template.information', ['column' => $column]), $information),
        ];
        foreach ($headers as $index => $header) {
            $sheet->getCell([$index + 1, 1])->setValueExplicit($header, DataType::TYPE_STRING);
        }

        $line = 1;
        $dateColumns = [];
        foreach ($definition->templateRows() as $values) {
            $line++;
            foreach ($values as $index => $value) {
                if ($this->write($sheet, $index + 1, $line, $value)) {
                    $dateColumns[$index + 1] = true;
                }
            }
        }

        foreach (array_keys($dateColumns) as $column) {
            $sheet->getStyle([$column, 2, $column, $line])->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        }

        $this->styleHeader($sheet, $columns, $required, count($information));
        $sheet->freezePane('A2');
        $sheet->setAutoFilter([1, 1, count($headers), $line]);
        for ($column = 1; $column <= count($headers); $column++) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    /** Écrit une valeur typée ; vrai pour une date. */
    private function write(Worksheet $sheet, int $column, int $line, mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        $cell = $sheet->getCell([$column, $line]);

        if ($value instanceof CarbonInterface) {
            $cell->setValueExplicit(ExcelDate::dateTimeToExcel($value), DataType::TYPE_NUMERIC);

            return true;
        }

        if (is_int($value) || is_float($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_NUMERIC);

            return false;
        }

        // Texte explicite : un code « 00123 » ou un téléphone garde ses zéros.
        $text = (string) $value;
        $cell->setValueExplicit($text, DataType::TYPE_STRING);
        if (in_array($text[0], self::FORMULA_PREFIXES, true)) {
            $cell->getStyle()->setQuotePrefix(true);
        }

        return false;
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, int>  $required
     */
    private function styleHeader(Worksheet $sheet, array $columns, array $required, int $information): void
    {
        $last = count($columns) + $information;
        $header = $sheet->getStyle([1, 1, $last, 1]);
        $header->getFont()->setBold(true);
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::HEADER_FILL);

        foreach ($columns as $index => $column) {
            if (isset($required[$column])) {
                $sheet->getStyle([$index + 1, 1])->getFill()->getStartColor()->setARGB(self::REQUIRED_FILL);
                $sheet->getComment([$index + 1, 1])->getText()->createText(__('imports.template.required'));
            }
        }

        if ($information > 0) {
            $sheet->getStyle([count($columns) + 1, 1, $last, 1])->getFont()->setItalic(true)
                ->getColor()->setARGB(self::INFORMATION_FONT);
        }
    }
}
