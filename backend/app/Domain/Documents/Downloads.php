<?php

namespace App\Domain\Documents;

use Closure;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Téléchargements synchrones (I-83) : jamais écrits sur le disque, jamais
 * mis en cache (`no-store`), toujours en pièce jointe (§8.4, ENF-13).
 */
final class Downloads
{
    /**
     * Corps produit au fil de l'eau sur la sortie (CSV, XLSX) : `$write`
     * écrit sur `php://output`.
     *
     * @param  Closure(): void  $write
     */
    public static function stream(string $filename, string $contentType, Closure $write): StreamedResponse
    {
        return response()->streamDownload($write, $filename, self::headers($contentType));
    }

    /** Document produit d'un bloc (PDF), en pièce jointe. */
    public static function content(string $filename, string $contentType, string $body): Response
    {
        $response = response($body, 200, self::headers($contentType));
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, self::asciiName($filename)));

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private static function headers(string $contentType): array
    {
        return [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    private static function asciiName(string $filename): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?? 'document';
    }
}
