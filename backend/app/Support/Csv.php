<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports CSV ouverts dans Excel : séparateur « ; », UTF-8 avec BOM, et
 * neutralisation des cellules interprétables comme formules (§8.4).
 */
final class Csv
{
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public static function sanitize(mixed $value): string
    {
        $value = match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };

        if ($value !== '' && in_array($value[0], self::FORMULA_PREFIXES, true)) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * @param  list<string>  $header
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map(self::sanitize(...), $header), ';', '"', '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(self::sanitize(...), $row), ';', '"', '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
