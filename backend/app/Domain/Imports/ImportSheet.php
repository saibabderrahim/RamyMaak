<?php

namespace App\Domain\Imports;

use Closure;
use Generator;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Calculation\Information\ExcelError;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Première feuille d'un classeur importé (§8.3) : ligne 1 = en-têtes (casse,
 * accents et espaces ignorés), lignes suivantes = données ; les lignes vides
 * sont ignorées.
 */
final class ImportSheet
{
    /**
     * @param  array<int, string>  $header  index de colonne (1 = A) → en-tête normalisé
     */
    private function __construct(
        private readonly Spreadsheet $spreadsheet,
        private readonly Worksheet $sheet,
        private readonly array $header,
    ) {}

    /**
     * Facteur appliqué au maximum de lignes pour le contrôle préalable :
     * listWorksheetInfo compte aussi les lignes vides seulement mises en forme
     * (bordures, couleurs). La limite exacte porte sur les lignes de données
     * (ImportAnalyzer) ; ce contrôle n'écarte que les feuilles démesurées.
     */
    private const DECLARED_ROWS_FACTOR = 10;

    /**
     * @param  list<string>|null  $columns  en-têtes normalisés à charger (null : tous) ;
     *                                      les autres colonnes ne sont pas lues (mémoire)
     *
     * @throws UnreadableSheet classeur illisible ou trop volumineux
     */
    public static function open(string $path, int $maxRows, ?array $columns = null): self
    {
        $reader = new XlsxReader;

        try {
            $first = $reader->listWorksheetInfo($path)[0] ?? null;
        } catch (Throwable) {
            $first = null;
        }

        if ($first === null) {
            throw new UnreadableSheet(__('imports.errors.unreadable'));
        }

        if ($first['totalRows'] > $maxRows * self::DECLARED_ROWS_FACTOR) {
            throw new UnreadableSheet(__('imports.errors.too_many_rows', ['max' => number_format($maxRows, 0, ',', "\u{202F}")]));
        }

        try {
            // Première lecture : la ligne d'en-tête seule, pour choisir les colonnes.
            $letters = null;
            if ($columns !== null) {
                $letters = [];
                foreach (self::readHeader(self::reader($first['worksheetName'], fn (string $column, int $row) => $row === 1), $path) as $index => $name) {
                    if (in_array($name, $columns, true)) {
                        $letters[Coordinate::stringFromColumnIndex($index)] = true;
                    }
                }
            }

            $spreadsheet = self::reader(
                $first['worksheetName'],
                fn (string $column, int $row) => $row === 1 || $letters === null || isset($letters[$column]),
            )->load($path);
        } catch (Throwable) {
            throw new UnreadableSheet(__('imports.errors.unreadable'));
        }

        $sheet = $spreadsheet->getSheet(0);

        return new self($spreadsheet, $sheet, self::headerOf($sheet));
    }

    /**
     * @param  Closure(string $column, int $row): bool  $accept
     */
    private static function reader(string $worksheetName, Closure $accept): XlsxReader
    {
        $reader = new XlsxReader;
        $reader->setLoadSheetsOnly([$worksheetName]);
        $reader->setReadEmptyCells(false);
        $reader->setIncludeCharts(false);
        $reader->setReadFilter(new class($accept) implements IReadFilter
        {
            /** @param Closure(string, int): bool $accept */
            public function __construct(private readonly Closure $accept) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return ($this->accept)($columnAddress, $row);
            }
        });

        return $reader;
    }

    /**
     * @return array<int, string>
     */
    private static function readHeader(XlsxReader $reader, string $path): array
    {
        $spreadsheet = $reader->load($path);
        $header = self::headerOf($spreadsheet->getSheet(0));
        $spreadsheet->disconnectWorksheets();

        return $header;
    }

    /**
     * @return array<int, string> index de colonne (1 = A) → en-tête normalisé
     */
    private static function headerOf(Worksheet $sheet): array
    {
        $header = [];
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn(1));
        for ($column = 1; $column <= $lastColumn; $column++) {
            $name = self::cell($sheet, $column, 1, ExcelDate::CALENDAR_WINDOWS_1900)->text;
            if ($name !== null && ($normalized = self::normalizeHeader($name)) !== '') {
                $header[$column] = $normalized;
            }
        }

        return $header;
    }

    /** « Code Client », « code_client » et « CODE_CLIENT* » désignent la même colonne. */
    public static function normalizeHeader(string $name): string
    {
        $name = mb_strtolower(Str::ascii(trim($name)));
        $name = rtrim($name, " *\t");

        return (string) preg_replace('/[\s-]+/', '_', $name);
    }

    /**
     * @return array<int, string> index de colonne → en-tête normalisé
     */
    public function header(): array
    {
        return $this->header;
    }

    /**
     * Lignes non vides, numérotées comme dans Excel.
     *
     * @param  array<string, int>  $columns  colonne → index de colonne
     * @return Generator<int, array<string, SheetCell>>
     */
    public function rows(array $columns): Generator
    {
        $calendar = $this->spreadsheet->getExcelCalendar();
        $lastRow = $this->sheet->getHighestDataRow();

        for ($line = 2; $line <= $lastRow; $line++) {
            $cells = [];
            foreach ($columns as $name => $column) {
                $cell = self::cell($this->sheet, $column, $line, $calendar);
                if (! $cell->isEmpty()) {
                    $cells[$name] = $cell;
                }
            }

            if ($cells !== []) {
                yield $line => $cells;
            }
        }
    }

    public function release(): void
    {
        $this->spreadsheet->disconnectWorksheets();
    }

    private static function cell(Worksheet $sheet, int $column, int $line, int $calendar): SheetCell
    {
        if (! $sheet->cellExists([$column, $line])) {
            return SheetCell::empty();
        }

        $cell = $sheet->getCell([$column, $line]);
        $value = $cell->getValue();

        if ($cell->isFormula()) {
            $value = $cell->getOldCalculatedValue();
            if ($value === null) {
                try {
                    $value = $cell->getCalculatedValue();
                } catch (Throwable) {
                    return SheetCell::failure(ExcelError::VALUE());
                }
            }
        } elseif ($cell->getDataType() === DataType::TYPE_ERROR) {
            return SheetCell::failure((string) $value);
        }

        if (is_string($value) && in_array($value, ExcelError::ERROR_CODES, true) && $cell->isFormula()) {
            return SheetCell::failure($value);
        }

        return match (true) {
            $value instanceof RichText => self::textCell($value->getPlainText()),
            is_string($value) => self::textCell($value),
            is_bool($value) => SheetCell::boolean($value),
            is_int($value), is_float($value) => self::numberCell($cell, $value, $calendar),
            default => SheetCell::empty(),
        };
    }

    private static function textCell(string $text): SheetCell
    {
        $text = trim(str_replace(["\u{00A0}", "\u{202F}"], ' ', $text));

        return $text === '' ? SheetCell::empty() : SheetCell::text($text);
    }

    private static function numberCell(Cell $cell, int|float $value, int $calendar): SheetCell
    {
        $format = (string) $cell->getStyle()->getNumberFormat()->getFormatCode();

        // Ce que l'utilisateur voit : « 19 % » vaut 19, pas 0,19.
        if (str_contains($format, '%')) {
            $value = round($value * 100, 10);
            $format = NumberFormat::FORMAT_GENERAL;
        }

        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            $text = sprintf('%.0f', $value);
        } else {
            $text = (string) $value;
        }

        // Format personnalisé « 0000000000 » (téléphone, NIF…) : zéros de tête conservés.
        if ($format !== NumberFormat::FORMAT_GENERAL && $format !== '' && ! ExcelDate::isDateTimeFormatCode($format)
            && preg_match('/^0+$/', $format) === 1) {
            $text = NumberFormat::toFormattedString($value, $format);
        }

        return SheetCell::number($value, $text, $calendar);
    }
}
