<?php

namespace App\Domain\Documents;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Dossier de travail d'OpenSpout pour un téléchargement XLSX : OpenSpout écrit
 * les feuilles et l'archive sur le disque avant de les copier dans la réponse.
 * Un dossier par téléchargement, retiré à la fin de l'écriture et, faute de
 * quoi, à l'arrêt du script : un client qui coupe la connexion ou une erreur
 * fatale interrompent la copie sans exécuter le `finally` de l'écriture, et
 * laisseraient sinon les données de la journée sur le disque (I-83).
 */
final class WorkFolder
{
    public static function create(): string
    {
        $base = config('ramymaak.documents.temp_dir');
        $base = is_string($base) && $base !== '' ? $base : sys_get_temp_dir();
        $folder = rtrim($base, '/').'/ramymaak-xlsx-'.Str::random(16);
        File::ensureDirectoryExists($folder, 0700);
        register_shutdown_function(static fn () => self::remove($folder));

        return $folder;
    }

    public static function remove(string $folder): void
    {
        if (is_dir($folder)) {
            File::deleteDirectory($folder);
        }
    }
}
