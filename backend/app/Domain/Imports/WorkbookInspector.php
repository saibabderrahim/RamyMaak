<?php

namespace App\Domain\Imports;

use ZipArchive;

/**
 * Contrôle du contenu d'un fichier déposé (§8.4, §12 « Fichiers ») : un
 * classeur .xlsx est une archive ZIP au format Office Open XML. On refuse
 * tout le reste (.xls binaire, texte renommé…), les classeurs à macros
 * (projet VBA, feuilles de macros Excel 4.0, classeur .xlsm renommé) et les
 * archives dont la taille décompressée trahit une « bombe ZIP ».
 */
final class WorkbookInspector
{
    /** Taille décompressée maximale (un fichier de 5 Mo dépasse rarement 60 Mo). */
    public const MAX_UNCOMPRESSED_BYTES = 100 * 1024 * 1024;

    public const MAX_ENTRIES = 5000;

    private const WORKBOOK_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';

    /** Marqueurs de macros, dans les noms de parties ou les types de contenu. */
    private const MACRO_MARKERS = ['vbaproject', 'macroenabled', 'macrosheet', 'vbadata'];

    /**
     * Message d'erreur à afficher, ou null si le fichier est un classeur
     * .xlsx acceptable.
     */
    public static function problem(string $path): ?string
    {
        $zip = new ZipArchive;
        if (! is_file($path) || $zip->open($path, ZipArchive::RDONLY) !== true) {
            return __('imports.file.format');
        }

        try {
            return self::inspect($zip);
        } finally {
            $zip->close();
        }
    }

    private static function inspect(ZipArchive $zip): ?string
    {
        if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
            return __('imports.file.format');
        }

        $uncompressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);
            if ($entry === false) {
                return __('imports.file.format');
            }

            if (self::mentionsMacros($entry['name'])) {
                return __('imports.file.macros');
            }

            $uncompressed += (int) $entry['size'];
            if ($uncompressed > self::MAX_UNCOMPRESSED_BYTES) {
                return __('imports.file.format');
            }
        }

        $contentTypes = $zip->getFromName('[Content_Types].xml');
        if ($contentTypes === false) {
            return __('imports.file.format');
        }

        if (self::mentionsMacros($contentTypes)) {
            return __('imports.file.macros');
        }

        // Classeur ordinaire (.xlsx) ; un modèle (.xltx) ou un classeur
        // binaire (.xlsb) n'est pas accepté.
        return str_contains($contentTypes, self::WORKBOOK_CONTENT_TYPE) ? null : __('imports.file.format');
    }

    private static function mentionsMacros(string $text): bool
    {
        $text = strtolower($text);

        foreach (self::MACRO_MARKERS as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }
}
