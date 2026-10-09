<?php

namespace Tests\Feature\Imports;

use Closure;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ZipArchive;

/**
 * Classeurs de test construits avec PhpSpreadsheet : première ligne =
 * en-têtes, une valeur commençant par « = » est une formule.
 */
final class Workbook
{
    /**
     * @param  list<list<mixed>>  $rows
     * @param  (Closure(Worksheet): void)|null  $tweak  retouches (formats, dates…)
     */
    public static function bytes(array $rows, ?Closure $tweak = null): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($rows, null, 'A1', true);
        if ($tweak !== null) {
            $tweak($sheet);
        }

        $path = tempnam(sys_get_temp_dir(), 'import-test-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /**
     * @param  list<list<mixed>>  $rows
     * @param  (Closure(Worksheet): void)|null  $tweak
     */
    public static function upload(array $rows, string $name = 'fichier.xlsx', ?Closure $tweak = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::bytes($rows, $tweak));
    }

    /** Classeur valide auquel on ajoute une partie (ex. projet VBA d'un .xlsm). */
    public static function withExtraPart(string $bytes, string $part, string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'import-test-');
        file_put_contents($path, $bytes);

        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString($part, $content);
        $zip->close();

        $result = (string) file_get_contents($path);
        unlink($path);

        return $result;
    }

    /** Relit un classeur reçu (gabarit, fichier d'erreurs). */
    public static function read(string $bytes): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'import-test-');
        file_put_contents($path, $bytes);

        try {
            return IOFactory::createReader('Xlsx')->load($path);
        } finally {
            unlink($path);
        }
    }

    /**
     * Valeurs de la première feuille, lignes indexées à partir de 1.
     *
     * @return array<int, array<int, mixed>>
     */
    public static function values(string $bytes): array
    {
        $spreadsheet = self::read($bytes);
        $sheet = $spreadsheet->getSheet(0);
        $rows = [];
        foreach ($sheet->toArray(null, false, false, false) as $index => $row) {
            $rows[$index + 1] = $row;
        }
        $spreadsheet->disconnectWorksheets();

        return $rows;
    }
}
